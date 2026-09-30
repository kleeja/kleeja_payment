<?php

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

class Subscription
{
    //the users that were asked about, by their ids
    private $users = [];
    //the packages, loaded when they are needed
    private $subscriptions = null;

    private function active(): bool
    {
        global $config;

        return ! empty($config['kjp_active_subscriptions']);
    }

    private function packages(): array
    {
        global $SQL, $dbprefix;

        if ($this->subscriptions === null) {
            $this->subscriptions = [];

            if ($this->active()) {
                $result = $SQL->build(['SELECT' => '*', 'FROM' => "{$dbprefix}subscriptions"]);

                while ($sub = $SQL->fetch($result)) {
                    $this->subscriptions[$sub['id']] = $sub;
                }

                $SQL->freeresult($result);
            }
        }

        return $this->subscriptions;
    }

    private function user($user_id)
    {
        global $SQL, $dbprefix;

        $user_id = (int) $user_id;

        // GUEST DONT HAVE SUBSCRIPTION
        if ($user_id <= 0 || ! $this->active()) {
            return false;
        }

        if (! array_key_exists($user_id, $this->users)) {
            $result = $SQL->build([
                'SELECT' => 'u.id, u.name, u.package, u.package_expire, u.group_id',
                'FROM' => "{$dbprefix}users u",
                'WHERE' => 'u.id = :id',
                'BIND' => ['id' => $user_id],
            ]);

            $this->users[$user_id] = $SQL->fetch($result);
            $SQL->freeresult($result);
        }

        return $this->users[$user_id];
    }

    /**
     * the package of a user was changed, read it again the next time
     *
     * @param mixed $user_id
     */
    public function forget($user_id)
    {
        unset($this->users[(int) $user_id]);
    }

    public function expire_at($subscripe_id, $time = false)
    {
        $subscripe = $this->packages()[$subscripe_id] ?? false;

        if (! $subscripe) {
            return false;
        }

        return ($time ? $time : time()) + $subscripe['days'] * 86400;
    }

    public function get($subscripe_id = 0)
    {
        if ($subscripe_id) {
            return $this->packages()[$subscripe_id] ?? false;
        }

        return $this->packages();
    }

    public function user_subscripe($user_id)
    {
        $user = $this->user($user_id);

        return $user ? $this->packages()[$user['package']] ?? '' : '';
    }

    /**
     * check if a user have subscription or not
     *
     * @param mixed $user_id
     */
    public function is_valid($user_id = 0)
    {
        $user = $this->user($user_id);

        if (! $user || ! $user['package']) {
            return false;
        }

        return time() < $user['package_expire'];
    }

    public function getMembersCount($subscripe_id)
    {
        global $SQL, $dbprefix;

        $result = $SQL->build([
            'SELECT' => 'COUNT(u.id) AS members',
            'FROM' => "{$dbprefix}users u",
            'WHERE' => 'u.package = :package AND u.package_expire > :now',
            'BIND' => ['package' => (int) $subscripe_id, 'now' => time()],
        ]);

        $row = $SQL->fetch($result);
        $SQL->freeresult($result);

        return (int) ($row['members'] ?? 0);
    }

    public function addPoint($file_id)
    {
        global $SQL, $dbprefix, $usrcp;

        $file_id = (int) $file_id;
        $file = getFileInfo($file_id, 'user');
        // first , let's check if the file owner have receive profits permissions
        $file_owner = $this->user($file ? $file['user'] : 0);

        if (! $file_owner || ! kjp_can('recaive_profits', (int) $file_owner['group_id'])) {
            return;
        }

        $user = $this->user($usrcp->id());

        if (! $user) {
            return;
        }

        $subscription_id = (int) $user['package'];
        // subscription_hash -> maybe this user renew the subscription and download this file again , so we need to add a point also again
        $subscripe_hash = sha1($user['id'] . $subscription_id . $user['package_expire']);

        $result = $SQL->build([
            'SELECT' => 'p.id',
            'FROM' => "{$dbprefix}subscription_point p",
            'WHERE' => 'p.user = :user AND p.file_id = :file_id AND p.subscripe_hash = :hash',
            'BIND' => ['user' => (int) $user['id'], 'file_id' => $file_id, 'hash' => $subscripe_hash],
        ]);

        $exists = $SQL->fetch($result);
        $SQL->freeresult($result);

        // this is first time !!
        if (! $exists) {
            $SQL->build([
                'INSERT' => 'user, file_id, subscription_id, subscripe_hash, time',
                'INTO' => "{$dbprefix}subscription_point",
                'VALUES' => ':user, :file_id, :subscription_id, :hash, :time',
                'BIND' => [
                    'user' => (int) $user['id'],
                    'file_id' => $file_id,
                    'subscription_id' => $subscription_id,
                    'hash' => $subscripe_hash,
                    'time' => time(),
                ],
            ]);

            $SQL->build([
                'UPDATE' => "{$dbprefix}users",
                'SET' => 'subs_point = subs_point + 1',
                'WHERE' => 'id = :id',
                'BIND' => ['id' => (int) $file_owner['id']],
            ]);
        }
    }

    /**
     * to convert the subscription points to amount
     * we need to know which user have not valid subscription and the subscriptiion id id not zero in the db
     * then we need to get the subscription information & and the file owner profits persentage
     * point price = ($subscription_info['price'] * $config['kjp_file_owner_profits'] / 100) / $pointsCount;
     * @return void
     */
    public function convertPoints()
    {
        global $SQL, $dbprefix, $config;

        if (! $this->active()) {
            return;
        }

        $result = $SQL->build([
            'SELECT' => 'u.id, u.package',
            'FROM' => "{$dbprefix}users u",
            'WHERE' => 'u.package > 0 AND u.package_expire <= :now',
            'BIND' => ['now' => time()],
        ]);

        $expired = [];

        while ($user = $SQL->fetch($result)) {
            $expired[] = $user;
        }

        $SQL->freeresult($result);

        //profits and taken points of the owners of the files, by their ids
        $owners = [];

        foreach ($expired as $user) {
            $user_id = (int) $user['id'];

            //this page is called by many visitors at the same time, only one of them converts the points of a user
            $SQL->build([
                'UPDATE' => "{$dbprefix}users",
                'SET' => 'package = 0, package_expire = 0',
                'WHERE' => 'id = :id AND package > 0',
                'BIND' => ['id' => $user_id],
            ]);

            if ($SQL->affected() !== 1) {
                continue;
            }

            $this->forget($user_id);

            $result = $SQL->build([
                'SELECT' => 'p.file_id',
                'FROM' => "{$dbprefix}subscription_point p",
                'WHERE' => 'p.user = :user',
                'BIND' => ['user' => $user_id],
            ]);

            $files = [];

            while ($point = $SQL->fetch($result)) {
                $files[] = (int) $point['file_id'];
            }

            $SQL->freeresult($result);

            $SQL->build([
                'DELETE' => "{$dbprefix}subscription_point",
                'WHERE' => 'user = :user',
                'BIND' => ['user' => $user_id],
            ]);

            $subscription_info = $this->packages()[$user['package']] ?? false;

            // check the points counts , Divide by zero -> is danger
            if (! $subscription_info || ! $files) {
                continue;
            }

            $pointPrice = ($subscription_info['price'] * $config['kjp_file_owner_profits']) / 100 / count($files);

            $result = $SQL->build([
                'SELECT' => 'f.id, f.user',
                'FROM' => "{$dbprefix}files f",
                'WHERE' => 'f.price > 0 AND f.id IN (:ids)',
                'BIND' => ['ids' => array_values(array_unique($files))],
            ]);

            $file_owners = [];

            while ($file = $SQL->fetch($result)) {
                $file_owners[$file['id']] = (int) $file['user'];
            }

            $SQL->freeresult($result);

            foreach ($files as $file_id) {
                $owner = $file_owners[$file_id] ?? 0;

                // the file owner is not guest
                if ($owner > 0) {
                    $owners[$owner] = $owners[$owner] ?? ['profit' => 0, 'points' => 0];
                    $owners[$owner]['profit'] += $pointPrice;
                    $owners[$owner]['points']++;
                }
            }
        }

        foreach ($owners as $owner => $totals) {
            if ($totals['profit'] > 0) {
                kjp_give_balance($owner, $totals['profit']);

                $SQL->build([
                    'UPDATE' => "{$dbprefix}users",
                    'SET' => 'subs_point = subs_point - :points',
                    'WHERE' => 'id = :id',
                    'BIND' => ['points' => $totals['points'], 'id' => $owner],
                ]);
            }
        }
    }
}
