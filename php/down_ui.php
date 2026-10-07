<?php

/*
 *
 * Download page -> User interface
 * it's a copy from the page of a file in 'do.php' with some change
 * i changed all hook names and other changes that i made , i added "edited" like a comment -> search about 'edited' word
 *
 */

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

define('IN_PAID_DOWNLOAD', true);

extract(runHook('KJP:begin_download_file', get_defined_vars()));

$query = [
    'SELECT' => 'f.id, f.real_filename, f.name, f.folder, f.size, f.time, f.uploads, f.type, f.price', // edited -> f.price
    'FROM' => "{$dbprefix}files f",
    'WHERE' => 'f.id = :id', // edited
    'LIMIT' => '1',
    'BIND' => ['id' => g('file', 'int')],
];

//if user system is default, we use users table
if ((int) $config['user_system'] == 1) {
    $query['SELECT'] .= ', u.name AS fusername, u.id AS fuserid';
    $query['JOINS'] = [
        [
            'LEFT JOIN' => "{$dbprefix}users u",
            'ON' => 'u.id=f.user',
        ],
    ];
}

extract(runHook('KJP:qr_download_file', get_defined_vars()));
$result = $SQL->build($query);

if ($SQL->num_rows($result) != 0) {
    $file_info = $SQL->fetch_array($result);

    $SQL->freeresult($result);

    // user dont have to be here if the file is for free
    if ($file_info['price'] == 0) {
        redirect($config['siteurl'] . 'do.php?id=' . $file_info['id']); // edited
    }

    // some vars
    $id = $file_info['id'];
    $name = $fname = $file_info['name'];
    $real_filename = $file_info['real_filename'];
    $type = $file_info['type'];
    $size = $file_info['size'];
    $time = $file_info['time'];
    $uploads = $file_info['uploads'];
    $price = kjp_price($file_info['price']); // edited

    // edited -> the names are saved encoded, they are not encoded again
    $name = $real_filename != '' ? str_replace('.' . $type, '', $real_filename) : $name;
    $name = strlen($name) > 70 ? substr($name, 0, 70) . '...' : $name;
    $fuserid = $file_info['fuserid'] ?? -1;
    $fusername = $config['user_system'] == 1 && $fuserid > -1 ? $file_info['fusername'] : false;
    $userfolder =
        $config['siteurl'] .
        ($config['mod_writer'] ? 'fileuser-' . $fuserid . '.html' : 'ucp.php?go=fileuser&amp;id=' . $fuserid);

    // edited -> the live extensions are not opened here, the buyer has to pay first

    $REPORT = $config['mod_writer']
        ? $config['siteurl'] . 'report-' . $file_info['id'] . '.html'
        : $config['siteurl'] . 'go.php?go=report&amp;id=' . $file_info['id'];
    $time = kleeja_date((int) $time);
    $size = readable_size((int) $size);

    $is_style_supported = is_style_supported(); // edited

    $sty = 'pay_download'; // edited

    // to allow the developer to make 'pay_download.html' with their styles .
    $styPath = kjp_template_path('pay_download'); // edited
    $title = $name . ' - ' . $olang['KJP_BUY']; // edited
} else {
    //file not exists
    extract(runHook('KJP:not_exists_qr_downlaod_file', get_defined_vars()));
    kleeja_err($lang['FILE_NO_FOUNDED']);
}

// $show_style = true;   edited

extract(runHook('KJP:b4_showsty_downlaod_file', get_defined_vars()));

$FormAction = $config['siteurl'] . 'do.php?file=' . $file_info['id'];
