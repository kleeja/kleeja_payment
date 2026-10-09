/*
 * Kleeja Payment, the charts of its stats on the Status Reports page of the plugin kleeja_advanced_stats.
 * The page loads ECharts before this file, php/status_reports.php puts the data in #kjpReportData.
 */
(function () {
    "use strict";

    var source = document.getElementById("kjpReportData");
    var charts = [];
    var data;

    if (!source || !window.echarts) {
        return;
    }

    try {
        data = JSON.parse(source.textContent);
    } catch (error) {
        return;
    }

    // the colours of the charts of the report (kleeja.net/branding), and of .kjp-swatch-* in status_reports.css
    function chartColors() {
        var dark = document.documentElement.getAttribute("data-bs-theme") === "dark";

        return dark
            ? {
                  series: ["#F45B69", "#BBC0C8", "#F78C96", "#717D8D"],
                  grid: "#3C4C61",
                  axis: "#BBC0C8",
                  text: "#FFFFFF",
                  surface: "#23354E",
                  pointer: "rgba(255, 255, 255, 0.06)",
                  shadow: "box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5); border-radius: 8px;",
              }
            : {
                  series: ["#F45B69", "#0B1F3A", "#F78C96", "#949CA8"],
                  grid: "#EDEEF0",
                  axis: "#546275",
                  text: "#0B1F3A",
                  surface: "#FFFFFF",
                  pointer: "rgba(11, 31, 58, 0.05)",
                  shadow: "box-shadow: 0 8px 24px rgba(11, 31, 58, 0.12); border-radius: 8px;",
              };
    }

    function extend(target, extra) {
        Object.keys(extra).forEach(function (key) {
            target[key] = extra[key];
        });

        return target;
    }

    function escapeHtml(text) {
        var span = document.createElement("span");
        span.textContent = String(text);

        return span.innerHTML;
    }

    function isRtl() {
        return document.documentElement.getAttribute("dir") === "rtl";
    }

    // like number_format() of PHP, with the currency of the payments
    function money(value) {
        return (
            Number(value || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
            " " +
            data.currency
        );
    }

    function tooltip(colors, rtl, trigger) {
        return {
            trigger: trigger,
            backgroundColor: colors.surface,
            borderColor: colors.grid,
            borderWidth: 1,
            padding: [8, 12],
            textStyle: { color: colors.text, fontSize: 13 },
            // the charts are drawn left to right, the tooltip takes the direction of the page back
            extraCssText: colors.shadow + (rtl ? " direction: rtl; text-align: right;" : ""),
            valueFormatter: money,
        };
    }

    // the points of the report are "2026-09-28", "2026-09" or "2026", in the language of the panel with Latin digits
    function pointLabels() {
        var keys = data.points || [];
        var years = keys.length && keys[0].slice(0, 4) !== keys[keys.length - 1].slice(0, 4);
        var options = null;
        var format = null;

        if (data.unit === "day") {
            options = { day: "numeric", month: "short", year: years ? "numeric" : undefined, timeZone: "UTC" };
        } else if (data.unit === "month") {
            options = { month: "short", year: "numeric", timeZone: "UTC" };
        }

        try {
            format = options
                ? new Intl.DateTimeFormat((document.documentElement.lang || "en") + "-u-nu-latn", options)
                : null;
        } catch (error) {
            format = null;
        }

        return keys.map(function (key) {
            var parts = key.split("-");

            return format ? format.format(new Date(Date.UTC(+parts[0], (+parts[1] || 1) - 1, +parts[2] || 1))) : key;
        });
    }

    // draw an ECharts chart in the element; build(colors, rtl) returns its options
    function mountChart(element, build) {
        if (!element) {
            return;
        }

        var chart = window.echarts.init(element, null, { renderer: "svg" });

        // without animation for the PDF file, a chart must be complete when it is captured
        function render(animate) {
            var options = build(chartColors(), isRtl());

            options.animation = animate !== false;
            chart.setOption(options, true);
        }

        render();
        charts.push({ chart: chart, render: render });

        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                chart.resize();
            }).observe(element);
        }
    }

    var labels = pointLabels();

    // the revenue of each point of the period, a part of the bar for each payment method
    mountChart(document.getElementById("kjpReportRevenue"), function (colors, rtl) {
        var methods = data.methods || [];

        return {
            aria: { enabled: true },
            color: colors.series,
            animationDuration: 400,
            textStyle: { fontFamily: window.getComputedStyle(document.body).fontFamily },
            grid: { left: 8, right: 8, top: 12, bottom: 4, containLabel: true },
            tooltip: extend(tooltip(colors, rtl, "axis"), {
                axisPointer: { type: "shadow", shadowStyle: { color: colors.pointer } },
            }),
            xAxis: {
                type: "category",
                data: labels,
                inverse: rtl,
                axisTick: { show: false },
                axisLine: { lineStyle: { color: colors.grid } },
                axisLabel: { color: colors.axis, fontSize: 12, hideOverlap: true },
            },
            yAxis: {
                type: "value",
                position: rtl ? "right" : "left",
                splitLine: { lineStyle: { color: colors.grid } },
                axisLabel: { color: colors.axis, fontSize: 12, hideOverlap: true },
            },
            series: methods.map(function (method, index) {
                return {
                    name: method.name,
                    type: "bar",
                    stack: "revenue",
                    data: method.data,
                    barMaxWidth: 18,
                    // round only on the top of the bar
                    itemStyle: {
                        color: colors.series[Math.min(index, 3)],
                        borderRadius: index === methods.length - 1 ? [4, 4, 0, 0] : 0,
                    },
                    emphasis: { focus: "series" },
                };
            }),
        };
    });

    // the revenue of files, groups and subscriptions; the list under the chart is its legend
    mountChart(document.getElementById("kjpReportTypes"), function (colors, rtl) {
        return {
            aria: { enabled: true },
            animationDuration: 400,
            textStyle: { fontFamily: window.getComputedStyle(document.body).fontFamily },
            tooltip: extend(tooltip(colors, rtl, "item"), {
                formatter: function (params) {
                    return (
                        params.marker +
                        escapeHtml(params.name) +
                        ": <b>" +
                        money(params.value) +
                        "</b> (" +
                        params.percent +
                        "%)"
                    );
                },
            }),
            series: [
                {
                    type: "pie",
                    radius: ["58%", "88%"],
                    avoidLabelOverlap: true,
                    label: { show: false },
                    itemStyle: { borderColor: colors.surface, borderWidth: 2 },
                    data: (data.types || []).map(function (type, index) {
                        return {
                            name: type.name,
                            value: type.value,
                            itemStyle: { color: colors.series[Math.min(index, 3)] },
                        };
                    }),
                },
            ],
        };
    });

    function redraw(animate) {
        charts.forEach(function (item) {
            item.chart.dispatchAction({ type: "hideTip" });
            item.chart.resize({ animation: { duration: 0 } });
            item.render(animate);
        });
    }

    document.addEventListener("kj:colormode", function () {
        redraw(true);
    });

    document.addEventListener("kj:layout", function () {
        charts.forEach(function (item) {
            item.chart.resize();
        });
    });

    // the PDF file of the report is made in light colours and in a wider layout, then the page goes back
    document.addEventListener("kj:pdf-export-start", function () {
        redraw(false);
    });

    document.addEventListener("kj:pdf-export-end", function () {
        redraw(false);
    });
})();
