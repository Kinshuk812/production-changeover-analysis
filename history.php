<?php

/*
 * Helper for safely displaying HTML text.
 */
function escapeHtml($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
 * Return JSON for the selected analysis.
 * This endpoint only reads existing records.
 */
function sendJsonResponse($statusCode, $data)
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    );

    exit;
}

/*
 * Detail request:
 * history.php?action=details&id=RUN_ID
 */
if (
    isset($_GET["action"]) &&
    $_GET["action"] === "details"
) {
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store");

    $requestedId = filter_var(
        $_GET["id"] ?? "",
        FILTER_VALIDATE_INT,
        array(
            "options" => array(
                "min_range" => 1
            )
        )
    );

    if ($requestedId === false) {
        sendJsonResponse(400, array(
            "success" => false,
            "message" => "A valid analysis run ID is required."
        ));
    }

    try {

        require_once "config/database.php";

        /*
         * Retrieve the summary for this one run.
         */
        $runStmt = $pdo->prepare(
            "SELECT
                id,
                created_at,
                setup_minutes,
                job_count,
                baseline_setup,
                grouped_setup,
                baseline_total,
                grouped_total
             FROM analysis_runs
             WHERE id = ?
             LIMIT 1"
        );

        $runStmt->execute(array($requestedId));

        $run = $runStmt->fetch(PDO::FETCH_ASSOC);

        if (!$run) {
            sendJsonResponse(404, array(
                "success" => false,
                "message" => "The requested analysis run was not found."
            ));
        }

        /*
         * Retrieve only the jobs belonging to this run.
         * The query does not change any stored data.
         */
        $jobStmt = $pdo->prepare(
            "SELECT
                job_code,
                product_family,
                processing_minutes,
                baseline_position,
                grouped_position
             FROM analysis_run_jobs
             WHERE run_id = ?
             ORDER BY baseline_position ASC"
        );

        $jobStmt->execute(array($requestedId));

        $savedJobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC);

        $jobs = array();
        $baselineSequence = array();

        foreach ($savedJobs as $job) {

            $normalisedJob = array(
                "job_code" => (string)$job["job_code"],
                "product_family" => (string)$job["product_family"],
                "processing_minutes" =>
                    (int)$job["processing_minutes"],
                "baseline_position" =>
                    (int)$job["baseline_position"],
                "grouped_position" =>
                    (int)$job["grouped_position"]
            );

            $jobs[] = $normalisedJob;

            $baselineSequence[] =
                $normalisedJob["product_family"];
        }

        /*
         * Build the grouped family sequence from the
         * saved grouped positions, not from a new
         * calculation or a new grouping operation.
         */
        $groupedJobs = $jobs;

        usort(
            $groupedJobs,
            function ($a, $b) {
                if (
                    $a["grouped_position"] ==
                    $b["grouped_position"]
                ) {
                    return 0;
                }

                return (
                    $a["grouped_position"] <
                    $b["grouped_position"]
                ) ? -1 : 1;
            }
        );

        $groupedSequence = array();

        foreach ($groupedJobs as $job) {
            $groupedSequence[] = $job["product_family"];
        }

        $baselineSetup = (int)$run["baseline_setup"];
        $groupedSetup = (int)$run["grouped_setup"];
        $baselineTotal = (int)$run["baseline_total"];
        $groupedTotal = (int)$run["grouped_total"];

        /*
         * These are display values derived from the
         * already-saved results.
         */
        $processingTotal =
            $baselineTotal - $baselineSetup;

        $setupDifference =
            $baselineSetup - $groupedSetup;

        sendJsonResponse(200, array(
            "success" => true,

            "run" => array(
                "id" => (int)$run["id"],
                "created_at" => (string)$run["created_at"],
                "job_count" => (int)$run["job_count"],
                "setup_minutes" => (int)$run["setup_minutes"],
                "processing_total" => $processingTotal,
                "baseline_setup" => $baselineSetup,
                "grouped_setup" => $groupedSetup,
                "baseline_total" => $baselineTotal,
                "grouped_total" => $groupedTotal,
                "setup_difference" => $setupDifference
            ),

            "baseline_sequence" => $baselineSequence,
            "grouped_sequence" => $groupedSequence,
            "jobs" => $jobs
        ));

    } catch (Throwable $e) {

        sendJsonResponse(500, array(
            "success" => false,
            "message" =>
                "The analysis details could not be loaded. Please try again."
        ));
    }
}


/*
 * Normal history page.
 * Keep the existing newest-first ordering.
 */
try {

    require_once "config/database.php";

    $stmt = $pdo->query(
        "SELECT *
         FROM analysis_runs
         ORDER BY id DESC"
    );

    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>History Temporarily Unavailable</title>
    </head>
    <body>
        <h1>History Temporarily Unavailable</h1>
        <p>
            The database is currently unavailable.
            Please try again later.
        </p>
    </body>
    </html>
    <?php
    exit;
}


?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Production Changeover Analysis | History</title>

    <meta
        name="description"
        content="Review saved production changeover analysis runs."
    >

    <style>

        :root {
            color-scheme: light;

            --ink: #1b2a25;
            --soft: #394b43;
            --paper: #f2f0e8;
            --surface: #fffef9;
            --line: #c8cec5;
            --muted: #4e5e55;

            --accent: #87351d;
            --accent-hover: #682815;

            --green: #17452e;
            --green-bg: #e5f0e7;

            --red: #76221d;
            --red-bg: #f8e8e3;

            --focus: #b45318;
        }

        * {
            box-sizing: border-box;
        }

        [hidden] {
            display: none !important;
        }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);

            font-family:
                system-ui,
                -apple-system,
                "Segoe UI",
                sans-serif;

            font-size: 1rem;
            line-height: 1.55;
        }

        a {
            color: var(--accent-hover);
            text-underline-offset: .2em;
        }

        a:focus-visible,
        button:focus-visible,
        summary:focus-visible,
        .table-wrap:focus-visible,
        .detail-table-wrap:focus-visible {
            outline: 3px solid var(--focus);
            outline-offset: 3px;
        }

        .skip-link {
            position: absolute;
            top: -5rem;
            left: 1rem;
            z-index: 20;

            padding: .8rem;

            background: #fff;
            color: var(--ink);
        }

        .skip-link:focus {
            top: 1rem;
        }

        /* Main application header */

        .masthead {
            background: var(--ink);
            color: #fffef9;
            border-bottom: 4px solid #b35b32;
        }

        .bar,
        main {
            width: min(calc(100% - 2rem), 76rem);
            margin-inline: auto;
        }

        .bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;

            padding-block: 1rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .75rem;

            color: #fffef9;
            text-decoration: none;
        }

        .brand-mark {
            display: grid;
            place-items: center;

            flex: 0 0 3.2rem;
            min-height: 3.2rem;

            border: 1px solid #b9c7bd;

            font-size: .85rem;
            font-weight: 800;
            line-height: 1.15;
            text-align: center;
        }

        .brand-name {
            display: block;
            font-size: .88rem;
            font-weight: 800;
            letter-spacing: .055em;
        }

        .brand-tagline {
            display: block;
            color: #d1dad3;
            font-size: .8rem;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: .15rem .5rem;
        }

        nav a {
            display: inline-flex;
            align-items: center;

            min-height: 44px;
            padding: .4rem .55rem;

            color: #fffef9;
            text-decoration: none;
        }

        nav a:hover,
        nav a[aria-current="page"] {
            border-bottom: 2px solid #e0a17c;
        }

        /* Page introduction */

        main {
            padding-block: clamp(1.5rem, 4vw, 3rem);
        }

        .intro {
            max-width: 70ch;

            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;

            border-bottom: 1px solid var(--line);
        }

        .eyebrow,
        .section-label {
            margin: 0 0 .4rem;

            color: var(--accent-hover);

            font-size: .74rem;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 19ch;

            margin: .2rem 0 .8rem;

            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.05;
            letter-spacing: -.04em;
        }

        h2 {
            margin: 0;

            font-size: clamp(1.25rem, 2.5vw, 1.65rem);
            line-height: 1.2;
        }

        h3 {
            margin: .2rem 0 .6rem;

            font-size: 1rem;
            line-height: 1.35;
        }

        p {
            max-width: 70ch;
        }

        .lede {
            margin: 0;
            color: var(--soft);
            font-size: 1.08rem;
        }

        /* History summary */

        .panel {
            min-width: 0;
            margin-bottom: 1rem;

            border: 1px solid var(--line);
            background: var(--surface);
        }

        .panel-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;

            padding: 1rem 1.2rem;
            border-bottom: 1px solid var(--line);
        }

        .panel-body {
            min-width: 0;
            padding: clamp(1rem, 2.5vw, 1.5rem);
        }

        .record-count,
        .run-id {
            display: inline-flex;
            align-items: center;

            min-height: 2rem;
            padding: .25rem .55rem;

            border: 1px solid var(--line);
            background: var(--paper);

            font-size: .86rem;
            font-variant-numeric: tabular-nums;
        }

        .run-id {
            font-family:
                ui-monospace,
                "Cascadia Code",
                Consolas,
                monospace;
        }

        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            min-height: 48px;
            margin-bottom: 1rem;
            padding: .65rem 1rem;

            border: 1px solid var(--accent-hover);

            color: var(--accent-hover);
            font-weight: 750;
            text-decoration: none;
        }

        .button-link:hover {
            background: #f4e9e1;
        }

        .table-wrap {
            max-width: 100%;
            overflow-x: auto;
            overscroll-behavior-inline: contain;

            border: 1px solid var(--line);
        }

        .history-table {
            width: 100%;
            min-width: 68rem;

            border-collapse: collapse;
            text-align: left;

            font-size: .9rem;
            font-variant-numeric: tabular-nums;
        }

        .history-table th,
        .history-table td {
            padding: .75rem .7rem;
            border-bottom: 1px solid #daddd4;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .history-table th {
            background: #e8e7de;

            font-size: .73rem;
            font-weight: 800;
            letter-spacing: .035em;
            text-transform: uppercase;
        }

        .history-table tbody tr:nth-child(even) {
            background: #f7f6f0;
        }

        .history-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .run-date {
            display: block;
            margin-top: .35rem;

            color: var(--muted);
            font-size: .8rem;
        }

        .value-pair {
            display: grid;
            gap: .55rem;
        }

        .value-pair span {
            display: block;
        }

        .value-label {
            display: block;
            color: var(--muted);
            font-size: .74rem;
        }

        .value-pair strong {
            font-size: .91rem;
            font-variant-numeric: tabular-nums;
        }

        .effect-good {
            color: var(--green);
            font-weight: 800;
        }

        .effect-more {
            color: var(--red);
            font-weight: 800;
        }

        .details-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            min-width: 44px;
            min-height: 44px;
            padding: .5rem .7rem;

            border: 1px solid var(--accent-hover);
            background: transparent;
            color: var(--accent-hover);

            font: inherit;
            font-size: .88rem;
            font-weight: 750;

            cursor: pointer;
        }

        .details-trigger:hover {
            background: #f4e9e1;
        }

        .empty-state {
            padding: 1rem;
            border-left: 4px solid var(--soft);
            background: #e8e7de;
        }

        .empty-state p {
            margin-bottom: 0;
        }

        footer {
            margin-top: 1.5rem;
            padding-top: 1rem;

            border-top: 1px solid var(--line);

            color: var(--muted);
            font-size: .84rem;
        }

        /* Large reusable details window */

        .detail-dialog {
            width: min(94vw, 88rem);
            max-width: calc(100vw - 1rem);

            height: min(92vh, 58rem);
            height: min(92dvh, 58rem);

            max-height: calc(100dvh - 1rem);

            margin: auto;
            padding: 0;

            overflow: hidden;

            border: 1px solid #7b8980;
            background: var(--surface);
            color: var(--ink);

            box-shadow: 0 1.5rem 5rem rgb(0 0 0 / 30%);
        }

        .detail-dialog[open] {
            display: flex;
            flex-direction: column;
        }

        .detail-dialog::backdrop {
            background: rgb(12 20 16 / 76%);
        }

        .dialog-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .8rem 1rem;

            flex: 0 0 auto;

            padding: 1rem clamp(1rem, 2.5vw, 1.75rem);

            background: var(--ink);
            color: #fffef9;
            border-bottom: 4px solid #b35b32;
        }

        .dialog-heading {
            min-width: 0;
        }

        .dialog-kicker {
            margin: 0 0 .35rem;

            color: #edc6ad;

            font-size: .73rem;
            font-weight: 800;
            letter-spacing: .085em;
            text-transform: uppercase;
        }

        .dialog-title {
            margin: 0;
            font-size: clamp(1.25rem, 3vw, 1.85rem);
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .dialog-subtitle {
            margin: .4rem 0 0;
            color: #d1dad3;
            font-size: .85rem;
            overflow-wrap: anywhere;
        }

        .close-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;

            min-width: 44px;
            min-height: 44px;
            padding: .55rem .8rem;

            flex: 0 0 auto;

            border: 1px solid #bdc9c0;
            background: transparent;
            color: #fffef9;

            font: inherit;
            font-weight: 750;

            cursor: pointer;
        }

        .close-button:hover {
            background: #394b43;
        }

        .dialog-status {
            flex: 0 0 auto;

            margin: 0;
            padding: .65rem clamp(1rem, 2.5vw, 1.75rem);

            border-bottom: 1px solid var(--line);

            background: #e8e7de;
            color: var(--muted);
            font-size: .88rem;
        }

        .dialog-status.is-error {
            background: var(--red-bg);
            color: var(--red);
            border-bottom-color: #dca8a1;
        }

        .dialog-body {
            min-height: 0;
            flex: 1 1 auto;

            overflow: auto;
            overscroll-behavior: contain;

            padding: clamp(1rem, 3vw, 1.75rem);
        }

        .dialog-section {
            margin-bottom: 1.5rem;
        }

        .dialog-section:last-child {
            margin-bottom: 0;
        }

        .dialog-section-heading {
            margin-bottom: .85rem;

            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem 1rem;
        }

        .dialog-section-heading p {
            margin: .25rem 0 0;

            color: var(--muted);
            font-size: .87rem;
        }

        /* Detail summary metrics */

        .detail-metrics {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(min(100%, 10rem), 1fr));

            border-top: 1px solid var(--line);
            border-left: 1px solid var(--line);
        }

        .detail-metric {
            min-width: 0;
            padding: 1rem;

            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .detail-metric-label {
            display: block;
            min-height: 2.2em;

            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .detail-metric-value {
            display: block;
            margin-top: .45rem;

            font-size: clamp(1.35rem, 3vw, 1.9rem);
            line-height: 1.18;
            letter-spacing: -.025em;

            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .detail-metric.good {
            background: var(--green-bg);
            color: var(--green);
        }

        .detail-metric.good .detail-metric-label {
            color: var(--green);
        }

        .detail-metric.increase {
            background: var(--red-bg);
            color: var(--red);
        }

        .detail-metric.increase .detail-metric-label {
            color: var(--red);
        }

        /* Original and grouped family sequences */

        .sequence-grid {
            display: grid;

            grid-template-columns:
                repeat(auto-fit, minmax(min(100%, 24rem), 1fr));

            gap: 1rem;
        }

        .sequence-card {
            min-width: 0;
            padding: 1rem;

            border: 1px solid var(--line);
            background: #f7f6f0;
        }

        .sequence-card.original {
            border-top: 4px solid var(--accent);
        }

        .sequence-card.grouped {
            border-top: 4px solid var(--green);
        }

        .sequence-card h3 {
            margin: 0 0 .7rem;
            font-size: 1rem;
        }

        .sequence-text {
            display: block;
            width: 100%;
            min-width: 0;

            padding: .85rem;

            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);

            font: .96rem/1.9 ui-monospace, "Cascadia Code", Consolas, monospace;

            white-space: normal;
            overflow-wrap: anywhere;
        }

        .sequence-caption {
            margin: .65rem 0 0;

            color: var(--muted);
            font-size: .84rem;
        }

        /* Full timing comparison */

        .time-grid {
            display: grid;

            grid-template-columns:
                repeat(auto-fit, minmax(min(100%, 22rem), 1fr));

            gap: 1rem;
        }

        .time-card {
            min-width: 0;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .time-card h3 {
            margin: 0;
            padding: .8rem 1rem;

            border-bottom: 1px solid var(--line);
            background: #e8e7de;
        }

        .time-card.original h3 {
            border-left: 4px solid var(--accent);
        }

        .time-card.grouped h3 {
            border-left: 4px solid var(--green);
        }

        .time-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem 1rem;

            padding: .8rem 1rem;
            border-bottom: 1px solid var(--line);
        }

        .time-row:last-child {
            border-bottom: 0;
        }

        .time-row strong {
            font-variant-numeric: tabular-nums;
        }

        /* Job-level record */

        .detail-table-wrap {
            max-width: 100%;
            overflow-x: auto;
            overscroll-behavior-inline: contain;

            border: 1px solid var(--line);
        }

        .detail-table {
            width: 100%;
            min-width: 42rem;

            border-collapse: collapse;
            text-align: left;

            font-size: .88rem;
            font-variant-numeric: tabular-nums;
        }

        .detail-table caption {
            padding: .7rem .8rem;

            background: #e8e7de;
            color: var(--muted);

            text-align: left;
            font-size: .83rem;
        }

        .detail-table th,
        .detail-table td {
            padding: .7rem .8rem;

            border-bottom: 1px solid #daddd4;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .detail-table th {
            background: #e8e7de;

            font-size: .73rem;
            font-weight: 800;
            letter-spacing: .035em;
            text-transform: uppercase;
        }

        .detail-table tbody tr:nth-child(even) {
            background: #f7f6f0;
        }

        .detail-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .dialog-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem 1rem;

            flex: 0 0 auto;

            padding: .7rem clamp(1rem, 2.5vw, 1.75rem);

            border-top: 1px solid var(--line);
            background: var(--paper);

            color: var(--muted);
            font-size: .82rem;
        }

        .dialog-footer p {
            margin: 0;
        }

    </style>

</head>

<body>

<a class="skip-link" href="#main">Skip to main content</a>

<header class="masthead">

    <div class="bar">

        <a class="brand" href="index.php">

            <span class="brand-mark" aria-hidden="true">PCA</span>

            <span>
                <span class="brand-name">PRODUCTION CHANGEOVER ANALYSIS</span>
                <span class="brand-tagline">Production sequence planning</span>
            </span>

        </a>

        <nav aria-label="Main navigation">
            <a href="index.php?new=1#job-entry">New analysis</a>
            <a href="history.php" aria-current="page">Analysis history</a>
            <a href="health.php">System health</a>
        </nav>

    </div>

</header>

<main id="main">

    <section class="intro">

        <p class="eyebrow">Production records / Analysis history</p>

        <h1>Analysis history</h1>

        <p class="lede">
            Review saved production comparisons. Open any run to inspect
            its family sequences, estimated timings, and job-level details.
        </p>

    </section>

    <section class="panel" aria-labelledby="history-title">

        <div class="panel-heading">

            <div>
                <p class="section-label">Saved records</p>
                <h2 id="history-title">Production analysis runs</h2>
            </div>

            <span class="record-count">
                <?php echo count($runs); ?>
                <?php echo count($runs) == 1 ? "record" : "records"; ?>
            </span>

        </div>

        <div class="panel-body">

            <p>
                <a class="button-link" href="index.php">
                    Start a new analysis
                </a>
            </p>

            <?php if (count($runs) == 0) { ?>

                <div class="empty-state">

                    <strong>No saved analyses yet.</strong>

                    <p>
                        Run a comparison first. Its summary will appear here.
                    </p>

                </div>

            <?php } else { ?>

                <div
                    class="table-wrap"
                    tabindex="0"
                    role="region"
                    aria-label="Saved production analysis history"
                >

                    <table class="history-table">

                        <caption>
                            Newest runs first. On narrow screens, scroll
                            horizontally inside the table.
                        </caption>

                        <thead>
                            <tr>
                                <th scope="col">Run / Date</th>
                                <th scope="col">Jobs</th>
                                <th scope="col">Setup per change</th>
                                <th scope="col">Processing time</th>
                                <th scope="col">Setup time</th>
                                <th scope="col">Total production time</th>
                                <th scope="col">Estimated setup effect</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($runs as $run) { ?>

                                <?php
                                $setupDifference =
                                    (int)$run["baseline_setup"] -
                                    (int)$run["grouped_setup"];

                                $processingTotal =
                                    (int)$run["baseline_total"] -
                                    (int)$run["baseline_setup"];
                                ?>

                                <tr>

                                    <td>
                                        <span class="run-id">
                                            #<?php echo escapeHtml($run["id"]); ?>
                                        </span>

                                        <span class="run-date">
                                            <?php echo escapeHtml($run["created_at"]); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo escapeHtml($run["job_count"]); ?>
                                    </td>

                                    <td>
                                        <?php echo escapeHtml($run["setup_minutes"]); ?>
                                        min
                                    </td>

                                    <td>
                                        <?php echo escapeHtml($processingTotal); ?>
                                        min
                                    </td>

                                    <td>
                                        <div class="value-pair">

                                            <span>
                                                <span class="value-label">Original</span>
                                                <strong>
                                                    <?php echo escapeHtml($run["baseline_setup"]); ?>
                                                    min
                                                </strong>
                                            </span>

                                            <span>
                                                <span class="value-label">Grouped</span>
                                                <strong>
                                                    <?php echo escapeHtml($run["grouped_setup"]); ?>
                                                    min
                                                </strong>
                                            </span>

                                        </div>
                                    </td>

                                    <td>
                                        <div class="value-pair">

                                            <span>
                                                <span class="value-label">Original</span>
                                                <strong>
                                                    <?php echo escapeHtml($run["baseline_total"]); ?>
                                                    min
                                                </strong>
                                            </span>

                                            <span>
                                                <span class="value-label">Grouped</span>
                                                <strong>
                                                    <?php echo escapeHtml($run["grouped_total"]); ?>
                                                    min
                                                </strong>
                                            </span>

                                        </div>
                                    </td>

                                    <td>

                                        <?php if ($setupDifference > 0) { ?>

                                            <span class="effect-good">
                                                <?php echo escapeHtml($setupDifference); ?>
                                                min lower
                                            </span>

                                        <?php } elseif ($setupDifference < 0) { ?>

                                            <span class="effect-more">
                                                <?php echo escapeHtml(abs($setupDifference)); ?>
                                                min higher
                                            </span>

                                        <?php } else { ?>

                                            <span>No change</span>

                                        <?php } ?>

                                    </td>

                                    <td>

                                        <button
                                            type="button"
                                            class="details-trigger"
                                            data-run-id="<?php echo escapeHtml($run["id"]); ?>"
                                            aria-label="View full details for analysis run <?php echo escapeHtml($run["id"]); ?>"
                                        >
                                            View details
                                        </button>

                                    </td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            <?php } ?>

        </div>

    </section>

    <footer>
        <p>
            Historical figures are estimates saved when each analysis was
            run. An estimated setup-time reduction is not a guarantee of
            actual production-time savings.
        </p>
    </footer>

</main>


<!--
    One reusable modal.
    Its content changes dynamically for the selected analysis run.
-->

<dialog
    id="detail-dialog"
    class="detail-dialog"
    aria-labelledby="dialog-title"
    aria-describedby="dialog-status"
>

    <div class="dialog-header">

        <div class="dialog-heading">

            <p class="dialog-kicker">Production record / Full details</p>

            <h2 class="dialog-title" id="dialog-title">
                Analysis details
            </h2>

            <p class="dialog-subtitle" id="dialog-subtitle">
                Selected production analysis
            </p>

        </div>

        <button
            type="button"
            class="close-button"
            id="close-dialog"
            aria-label="Close analysis details"
            autofocus
        >
            Close &#215;
        </button>

    </div>

    <p
        class="dialog-status"
        id="dialog-status"
        role="status"
        aria-live="polite"
    >
        Select an analysis run to load its details.
    </p>

    <div class="dialog-body" id="dialog-content" hidden>

        <section class="dialog-section" aria-labelledby="summary-heading">

            <div class="dialog-section-heading">
                <div>
                    <p class="section-label">01 / Overview</p>
                    <h3 id="summary-heading">Run summary</h3>
                </div>
            </div>

            <div class="detail-metrics" id="detail-metrics"></div>

        </section>

        <section class="dialog-section" aria-labelledby="sequence-heading">

            <div class="dialog-section-heading">
                <div>
                    <p class="section-label">02 / Quick comparison</p>
                    <h3 id="sequence-heading">Product-family sequences</h3>
                    <p>
                        Read the sequence from left to right. Repeated
                        letters indicate consecutive jobs from the same family.
                    </p>
                </div>
            </div>

            <div class="sequence-grid" id="detail-sequences"></div>

        </section>

        <section class="dialog-section" aria-labelledby="timing-heading">

            <div class="dialog-section-heading">
                <div>
                    <p class="section-label">03 / Timing</p>
                    <h3 id="timing-heading">Production-time comparison</h3>
                </div>
            </div>

            <div class="time-grid" id="detail-time-grid"></div>

        </section>

        <section class="dialog-section" aria-labelledby="jobs-heading">

            <div class="dialog-section-heading">
                <div>
                    <p class="section-label">04 / Job record</p>
                    <h3 id="jobs-heading">Job-level position mapping</h3>
                    <p>
                        Each row represents one saved job. The two position
                        columns show where that job appears in each sequence.
                    </p>
                </div>
            </div>

            <div
                class="detail-table-wrap"
                tabindex="0"
                role="region"
                aria-label="Detailed job positions for selected analysis"
            >

                <table class="detail-table">

                    <caption>
                        Rows are ordered by original position.
                        Grouped positions refer to the saved grouped sequence.
                    </caption>

                    <thead>
                        <tr>
                            <th scope="col">Original position</th>
                            <th scope="col">Grouped position</th>
                            <th scope="col">Job code</th>
                            <th scope="col">Product family</th>
                            <th scope="col">Processing time</th>
                        </tr>
                    </thead>

                    <tbody id="detail-jobs-body"></tbody>

                </table>

            </div>

        </section>

    </div>

    <div class="dialog-footer">

        <p>
            Read-only view of the selected saved analysis.
        </p>

        <p>
            Close this window to return to the history.
        </p>

    </div>

</dialog>


<script>
(function () {
    "use strict";

    const dialog = document.getElementById("detail-dialog");
    const closeButton = document.getElementById("close-dialog");

    const dialogTitle = document.getElementById("dialog-title");
    const dialogSubtitle = document.getElementById("dialog-subtitle");
    const dialogStatus = document.getElementById("dialog-status");
    const dialogContent = document.getElementById("dialog-content");

    const metricsContainer = document.getElementById("detail-metrics");
    const sequencesContainer = document.getElementById("detail-sequences");
    const timeContainer = document.getElementById("detail-time-grid");
    const jobsBody = document.getElementById("detail-jobs-body");

    let activeTrigger = null;
    let requestNumber = 0;

    /*
     * Create DOM elements using textContent.
     * Saved database values are never inserted as HTML.
     */
    function createElement(tag, className, text) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }

        return element;
    }

    function clearElement(element) {
        element.replaceChildren();
    }

    function setStatus(message, isError) {
        dialogStatus.textContent = message;
        dialogStatus.classList.toggle("is-error", Boolean(isError));
    }

    function addMetric(label, value, extraClass) {
        const card = createElement(
            "div",
            "detail-metric" + (extraClass ? " " + extraClass : "")
        );

        card.appendChild(
            createElement("span", "detail-metric-label", label)
        );

        card.appendChild(
            createElement("strong", "detail-metric-value", value)
        );

        metricsContainer.appendChild(card);
    }

    function addSequenceCard(title, sequence, type, caption) {
        const card = createElement("section", "sequence-card " + type);

        card.appendChild(
            createElement("h3", "", title)
        );

        const sequenceText = createElement(
            "code",
            "sequence-text",
            sequence.length > 0
                ? sequence.join(" → ")
                : "No saved job details are available for this run."
        );

        card.appendChild(sequenceText);

        card.appendChild(
            createElement("p", "sequence-caption", caption)
        );

        sequencesContainer.appendChild(card);
    }

    function addTimeCard(title, type, setupTime, totalTime) {
        const card = createElement("section", "time-card " + type);

        card.appendChild(createElement("h3", "", title));

        const setupRow = createElement("div", "time-row");

        setupRow.appendChild(
            createElement("span", "", "Estimated setup time")
        );

        setupRow.appendChild(
            createElement("strong", "", setupTime + " min")
        );

        card.appendChild(setupRow);

        const totalRow = createElement("div", "time-row");

        totalRow.appendChild(
            createElement("span", "", "Estimated production time")
        );

        totalRow.appendChild(
            createElement("strong", "", totalTime + " min")
        );

        card.appendChild(totalRow);

        timeContainer.appendChild(card);
    }

    function renderJobs(jobs) {
        clearElement(jobsBody);

        if (!jobs || jobs.length === 0) {
            const row = document.createElement("tr");
            const cell = createElement(
                "td",
                "",
                "No job-level records are available for this analysis."
            );

            cell.colSpan = 5;
            row.appendChild(cell);
            jobsBody.appendChild(row);

            return;
        }

        jobs.forEach(function (job) {
            const row = document.createElement("tr");

            const values = [
                job.baseline_position,
                job.grouped_position,
                job.job_code,
                job.product_family,
                job.processing_minutes + " min"
            ];

            values.forEach(function (value) {
                row.appendChild(
                    createElement("td", "", value)
                );
            });

            jobsBody.appendChild(row);
        });
    }

    function renderDetails(data) {
        const run = data.run;

        dialogTitle.textContent =
            "Analysis run #" + run.id;

        dialogSubtitle.textContent =
            "Recorded: " + run.created_at;

        clearElement(metricsContainer);
        clearElement(sequencesContainer);
        clearElement(timeContainer);
        clearElement(jobsBody);

        addMetric(
            "Jobs analysed",
            run.job_count
        );

        addMetric(
            "Setup per family change",
            run.setup_minutes + " min"
        );

        addMetric(
            "Total processing time",
            run.processing_total + " min"
        );

        let effectText = "No change";
        let effectClass = "";

        if (run.setup_difference > 0) {
            effectText = run.setup_difference + " min lower";
            effectClass = "good";
        } else if (run.setup_difference < 0) {
            effectText =
                Math.abs(run.setup_difference) + " min higher";
            effectClass = "increase";
        }

        addMetric(
            "Estimated setup effect",
            effectText,
            effectClass
        );

        addSequenceCard(
            "Original family sequence",
            data.baseline_sequence || [],
            "original",
            "Jobs remain in their entered order."
        );

        addSequenceCard(
            "Grouped family sequence",
            data.grouped_sequence || [],
            "grouped",
            "Jobs are grouped by product family using the saved positions."
        );

        addTimeCard(
            "Original sequence",
            "original",
            run.baseline_setup,
            run.baseline_total
        );

        addTimeCard(
            "Grouped sequence",
            "grouped",
            run.grouped_setup,
            run.grouped_total
        );

        renderJobs(data.jobs || []);

        dialogContent.hidden = false;

        if (data.jobs && data.jobs.length === 0) {
            setStatus(
                "The run summary loaded, but no saved job details were found.",
                false
            );
        } else {
            setStatus(
                "Details loaded for analysis run #" + run.id + ".",
                false
            );
        }
    }

    async function openDetails(button) {
        const runId = button.dataset.runId;

        activeTrigger = button;

        /*
         * Every opening gets a new request number.
         * This prevents a delayed earlier response from replacing
         * the details after the modal has been closed.
         */
        const thisRequest = ++requestNumber;

        dialogTitle.textContent = "Loading analysis run #" + runId;
        dialogSubtitle.textContent = "";
        dialogContent.hidden = true;

        clearElement(metricsContainer);
        clearElement(sequencesContainer);
        clearElement(timeContainer);
        clearElement(jobsBody);

        setStatus(
            "Loading saved details for analysis run #" + runId + "...",
            false
        );

        if (!dialog.open) {
            dialog.showModal();
        }

        closeButton.focus();

        try {
            const url = new URL("history.php", window.location.href);

            url.searchParams.set("action", "details");
            url.searchParams.set("id", runId);

            const response = await fetch(url.toString(), {
                method: "GET",
                headers: {
                    "Accept": "application/json"
                },
                credentials: "same-origin"
            });

            let data;

            try {
                data = await response.json();
            } catch (parseError) {
                throw new Error(
                    "The server returned an unreadable response. Please try again."
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message ||
                    "The selected analysis details could not be loaded."
                );
            }

            if (
                thisRequest !== requestNumber ||
                !dialog.open
            ) {
                return;
            }

            renderDetails(data);

        } catch (error) {

            if (
                thisRequest !== requestNumber ||
                !dialog.open
            ) {
                return;
            }

            dialogTitle.textContent =
                "Analysis run #" + runId;

            dialogSubtitle.textContent = "";

            dialogContent.hidden = true;

            setStatus(
                error.message ||
                "The details could not be loaded. Please try again.",
                true
            );
        }
    }

    document.querySelectorAll(".details-trigger").forEach(
        function (button) {
            button.addEventListener("click", function () {
                openDetails(button);
            });
        }
    );

    closeButton.addEventListener("click", function () {
        if (dialog.open) {
            dialog.close();
        }
    });

    /*
     * Native dialog behaviour handles Escape.
     * The close event also restores focus to the button that opened it.
     */
    dialog.addEventListener("close", function () {
        requestNumber++;

        const previousTrigger = activeTrigger;
        activeTrigger = null;

        if (
            previousTrigger &&
            document.contains(previousTrigger)
        ) {
            previousTrigger.focus();
        }
    });

    /*
     * Clicking the backdrop closes the modal.
     * Clicking inside its content does not.
     */
    dialog.addEventListener("click", function (event) {
        if (event.target === dialog && dialog.open) {
            dialog.close();
        }
    });

})();
</script>

</body>
</html>