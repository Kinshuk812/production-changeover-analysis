<?php

try {
    require_once "config/database.php";
} catch (Throwable $e) {
    http_response_code(503);
    header("Content-Type: text/html; charset=UTF-8");
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Database Temporarily Unavailable</title>
        <style>
            body {
                margin: 0;
                padding: 2rem;
                min-height: 100vh;
                box-sizing: border-box;
                display: grid;
                place-items: center;
                background: #f2f0e8;
                color: #1b2a25;
                font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            }

            .message {
                width: min(100%, 34rem);
                box-sizing: border-box;
                padding: 2rem;
                border: 1px solid #c8cec5;
                border-top: 5px solid #87351d;
                background: #fffef9;
            }

            h1 {
                margin-top: 0;
                font-size: 1.7rem;
            }

            p {
                line-height: 1.6;
            }

            a {
                color: #682815;
            }
        </style>
    </head>
    <body>
        <main class="message">
            <h1>Database temporarily unavailable</h1>
            <p>
                The application cannot connect to its database right now.
            </p>
            <p>
                Please try again later.
            </p>
            <p>
                <a href="index.php">Try loading the application again</a>
            </p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$message = "";
$error = "";
$result = null;

/*
 * Start with an empty form.
 * Example data is loaded only when the user requests it.
 */
$jobsText = "";
$setupMinutes = "";

function calculateSetupTime($jobs, $setupMinutes)
{
    $totalSetup = 0;
    $familyChanges = 0;

    $i = 1;

    while ($i < count($jobs)) {

        if ($jobs[$i]["family"] != $jobs[$i - 1]["family"]) {
            $totalSetup = $totalSetup + $setupMinutes;
            $familyChanges++;
        }

        $i++;
    }

    return array($totalSetup, $familyChanges);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $jobsText = $_POST["jobs"] ?? "";
    $setupMinutes = filter_var(
        $_POST["setup_minutes"] ?? "",
        FILTER_VALIDATE_INT
    );

    $jobs = array();
    $lines = preg_split("/\r\n|\r|\n/", trim($jobsText));

    if ($setupMinutes === false || $setupMinutes < 0) {

        $error = "Setup time must be a non-negative whole number.";

    } elseif (count($lines) < 1 || trim($jobsText) == "") {

        $error = "Please enter at least one production job.";

    } else {

        $jobCodes = array();
        $valid = true;
        $lineNumber = 0;

        foreach ($lines as $line) {

            $lineNumber++;

            if (trim($line) == "") {
                continue;
            }

            $parts = str_getcsv($line);

            if (count($parts) != 3) {
                $error = "Line " . $lineNumber .
                    " must contain job code, family and processing minutes.";
                $valid = false;
                break;
            }

            $jobCode = trim($parts[0]);
            $family = trim($parts[1]);
            $processing = filter_var(
                trim($parts[2]),
                FILTER_VALIDATE_INT
            );

            if (
                $jobCode == "" ||
                $family == "" ||
                strlen($jobCode) > 50 ||
                strlen($family) > 50
            ) {
                $error = "Line " . $lineNumber .
                    " contains a blank or invalid job code or family.";
                $valid = false;
                break;
            }

            if (isset($jobCodes[$jobCode])) {
                $error = "Job codes must be unique. Duplicate: " .
                    $jobCode;
                $valid = false;
                break;
            }

            if ($processing === false || $processing <= 0) {
                $error = "Processing time on line " . $lineNumber .
                    " must be a positive whole number.";
                $valid = false;
                break;
            }

            $jobCodes[$jobCode] = true;

            $jobs[] = array(
                "job_code" => $jobCode,
                "family" => $family,
                "processing" => $processing
            );
        }

        if ($valid && count($jobs) == 0) {
            $error = "Please enter at least one valid job.";
            $valid = false;
        }

        if ($valid && count($jobs) > 100) {
            $error = "Please enter no more than 100 jobs.";
            $valid = false;
        }

        if ($valid) {

            /*
             * Original job order.
             */

            $baselineJobs = $jobs;

            /*
             * Group jobs by product family.
             * Families retain their first-appearance order.
             * Job order within each family is preserved.
             */

            $families = array();

            foreach ($jobs as $job) {

                $family = $job["family"];

                if (!isset($families[$family])) {
                    $families[$family] = array();
                }

                $families[$family][] = $job;
            }

            $groupedJobs = array();

            foreach ($families as $familyJobs) {

                foreach ($familyJobs as $job) {
                    $groupedJobs[] = $job;
                }
            }

            list($baselineSetup, $baselineChanges) =
                calculateSetupTime($baselineJobs, $setupMinutes);

            list($groupedSetup, $groupedChanges) =
                calculateSetupTime($groupedJobs, $setupMinutes);

            $processingTotal = 0;

            foreach ($jobs as $job) {
                $processingTotal += $job["processing"];
            }

            $baselineTotal = $processingTotal + $baselineSetup;
            $groupedTotal = $processingTotal + $groupedSetup;

            $difference = $baselineSetup - $groupedSetup;

            try {

                $pdo->beginTransaction();

                $sql = "INSERT INTO analysis_runs
                    (
                        setup_minutes,
                        job_count,
                        baseline_setup,
                        grouped_setup,
                        baseline_total,
                        grouped_total
                    )
                    VALUES (?, ?, ?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);

                $stmt->execute(array(
                    $setupMinutes,
                    count($jobs),
                    $baselineSetup,
                    $groupedSetup,
                    $baselineTotal,
                    $groupedTotal
                ));

                $runId = $pdo->lastInsertId();

                $sql = "INSERT INTO analysis_run_jobs
                    (
                        run_id,
                        job_code,
                        product_family,
                        processing_minutes,
                        baseline_position,
                        grouped_position
                    )
                    VALUES (?, ?, ?, ?, ?, ?)";

                $jobStmt = $pdo->prepare($sql);

                $groupedPositions = array();

                $position = 1;

                foreach ($groupedJobs as $job) {
                    $groupedPositions[$job["job_code"]] = $position;
                    $position++;
                }

                $position = 1;

                foreach ($baselineJobs as $job) {

                    $jobStmt->execute(array(
                        $runId,
                        $job["job_code"],
                        $job["family"],
                        $job["processing"],
                        $position,
                        $groupedPositions[$job["job_code"]]
                    ));

                    $position++;
                }

                $pdo->commit();

                $result = array(
                    "run_id" => $runId,
                    "jobs" => $jobs,
                    "baseline_jobs" => $baselineJobs,
                    "grouped_jobs" => $groupedJobs,
                    "processing_total" => $processingTotal,
                    "baseline_setup" => $baselineSetup,
                    "grouped_setup" => $groupedSetup,
                    "baseline_changes" => $baselineChanges,
                    "grouped_changes" => $groupedChanges,
                    "baseline_total" => $baselineTotal,
                    "grouped_total" => $groupedTotal,
                    "difference" => $difference
                );

                $message = "Analysis completed and saved successfully.";

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "The analysis could not be saved. Please check the database configuration.";
            }
        }
    }
}

function escapeHtml($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
 * Display-only helper for the quick family sequence.
 */
function familySequenceText($jobs)
{
    $families = array();

    foreach ($jobs as $job) {
        $families[] = $job["family"];
    }

    return implode(" → ", $families);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Production Changeover Analysis</title>

    <meta
        name="description"
        content="Compare production job sequences and estimate machine changeover setup time."
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
            --accent-dark: #682815;
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
            font: 1rem/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        a {
            color: var(--accent-dark);
            text-underline-offset: .2em;
        }

        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        textarea:focus-visible,
        summary:focus-visible,
        .table-wrap:focus-visible {
            outline: 3px solid var(--focus);
            outline-offset: 3px;
        }

        .skip-link {
            position: absolute;
            top: -5rem;
            left: 1rem;
            z-index: 5;
            padding: .8rem;
            background: #fff;
            color: var(--ink);
        }

        .skip-link:focus {
            top: 1rem;
        }

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
            color: var(--accent-dark);
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

        .layout-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));
            gap: 1rem;
            align-items: start;
        }

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

        .guide-panel {
            border-top: 4px solid var(--soft);
            background: #e8e7de;
        }

        .field {
            margin-bottom: 1.1rem;
        }

        label {
            display: block;
            margin-bottom: .4rem;
            font-weight: 750;
        }

        .help,
        .note {
            display: block;
            color: var(--muted);
            font-size: .88rem;
        }

        .help {
            margin-top: .35rem;
        }

        textarea,
        input[type="number"] {
            display: block;
            width: 100%;
            min-width: 0;
            border: 1px solid #87958b;
            border-radius: 2px;
            padding: .75rem;
            background: #fff;
            color: var(--ink);
            font: inherit;
        }

        textarea {
            min-height: 13rem;
            resize: vertical;
            font: .94rem/1.8 ui-monospace, "Cascadia Code", Consolas, monospace;
        }

        input[type="number"] {
            min-height: 48px;
            max-width: 20rem;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: .6rem;
        }

        button,
        .link-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: .7rem 1rem;
            border: 1px solid var(--accent-dark);
            border-radius: 2px;
            font: inherit;
            font-weight: 750;
            text-align: center;
        }

        button {
            cursor: pointer;
        }

        .primary-button {
            flex: 1 1 13rem;
            background: var(--accent);
            color: #fff;
        }

        .primary-button:hover {
            background: var(--accent-dark);
        }

        .secondary-button,
        .link-button {
            background: transparent;
            color: var(--accent-dark);
            text-decoration: none;
        }

        .secondary-button:hover,
        .link-button:hover {
            background: #f4e9e1;
        }

        .example-status {
            margin: .65rem 0 0;
            color: var(--green);
            font-size: .88rem;
        }

        .notice {
            margin-bottom: 1rem;
            padding: .85rem 1rem;
            border-left: 5px solid;
        }

        .notice p {
            margin: .25rem 0 0;
        }

        .notice-success {
            border-color: var(--green);
            background: var(--green-bg);
            color: var(--green);
        }

        .notice-error {
            border-color: var(--red);
            background: var(--red-bg);
            color: var(--red);
        }

        .format-example {
            max-width: 100%;
            overflow-x: auto;
            padding: .8rem;
            border: 1px solid #c4c7bc;
            background: #ddded4;
            color: var(--ink);
            font: .88rem/1.8 ui-monospace, Consolas, monospace;
        }

        .guide-section {
            padding-block: .8rem;
            border-bottom: 1px solid #c4c7bc;
        }

        .guide-section:first-child {
            padding-top: 0;
        }

        .guide-section:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .guide-section p:last-child {
            margin-bottom: 0;
        }

        .guide-quick-list {
            margin-bottom: 1.2rem;
            padding-left: 1.25rem;
        }

        .full-guide {
            padding-top: .8rem;
            border-top: 1px solid var(--line);
        }

        .full-guide summary {
            display: flex;
            align-items: center;
            min-height: 44px;
            cursor: pointer;
            color: var(--accent-dark);
            font-weight: 800;
        }

        .full-guide[open] summary {
            margin-bottom: .5rem;
        }

        .submit-help {
            margin: .7rem 0 0;
            max-width: 60ch;
        }

        #analysis-results {
            scroll-margin-top: 1rem;
        }

        #results-title:focus {
            outline: 3px solid var(--focus);
            outline-offset: 4px;
        }

        .steps {
            display: grid;
            gap: .6rem;
            padding: 0;
            list-style: none;
            counter-reset: guide-step;
        }

        .steps li {
            counter-increment: guide-step;
            display: grid;
            grid-template-columns: 2rem minmax(0, 1fr);
            gap: .6rem;
        }

        .steps li::before {
            content: counter(guide-step, decimal-leading-zero);
            color: var(--accent-dark);
            font: 800 .8rem/2 ui-monospace, Consolas, monospace;
        }

        .steps strong {
            display: block;
        }

        .mini-sequence {
            margin: .45rem 0;
            padding: .65rem;
            border: 1px solid var(--line);
            background: var(--surface);
            font: .9rem/1.8 ui-monospace, Consolas, monospace;
            overflow-wrap: anywhere;
        }

        .formula {
            margin: .55rem 0;
            padding: .75rem;
            border-left: 3px solid var(--soft);
            background: #f7f6f0;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 9.5rem), 1fr));
            border-top: 1px solid var(--line);
            border-left: 1px solid var(--line);
        }

        .metric {
            min-width: 0;
            padding: 1rem;
            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .metric-label {
            display: block;
            min-height: 2.2em;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .metric strong {
            display: block;
            margin-top: .45rem;
            font-size: clamp(1.35rem, 3vw, 1.9rem);
            line-height: 1.15;
            letter-spacing: -.03em;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .metric small {
            font-size: .82rem;
            letter-spacing: 0;
        }

        .metric-good {
            background: var(--green-bg);
            color: var(--green);
        }

        .metric-good .metric-label {
            color: var(--green);
        }

        .sequence-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));
            gap: .8rem;
            margin-block: 1.2rem;
        }

        .sequence-card {
            min-width: 0;
            padding: 1rem;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .sequence-card-grouped {
            border-top: 4px solid var(--green);
        }

        .sequence-card-original {
            border-top: 4px solid var(--accent);
        }

        .sequence-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .sequence-title h3 {
            margin: 0;
        }

        .count-label,
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

        .sequence-string {
            display: block;
            margin-top: .8rem;
            padding: .85rem;
            border: 1px solid var(--line);
            background: #f1f0e8;
            color: var(--ink);
            font: .95rem/1.9 ui-monospace, "Cascadia Code", Consolas, monospace;
            overflow-wrap: anywhere;
        }

        .sequence-meta {
            margin: .65rem 0 0;
            color: var(--muted);
            font-size: .88rem;
        }

        .effect-note {
            margin: 1rem 0;
            padding: .85rem 1rem;
            border-left: 4px solid var(--green);
            background: #edf3ec;
        }

        .effect-note p {
            margin: .25rem 0 0;
        }

        .table-wrap {
            max-width: 100%;
            overflow-x: auto;
            overscroll-behavior-inline: contain;
            border: 1px solid var(--line);
        }

        table {
            width: 100%;
            min-width: 32rem;
            border-collapse: collapse;
            text-align: left;
            font-size: .92rem;
            font-variant-numeric: tabular-nums;
        }

        caption {
            padding: .6rem .75rem;
            background: #e8e7de;
            color: var(--muted);
            text-align: left;
            font-size: .82rem;
        }

        th,
        td {
            padding: .65rem .75rem;
            border-bottom: 1px solid #daddd4;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        th {
            background: #e8e7de;
            font-size: .74rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        tbody tr:nth-child(even) {
            background: #f7f6f0;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .total-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            margin: 0;
            padding: .8rem 0;
            border-bottom: 1px solid var(--line);
        }

        .total-line strong {
            font-variant-numeric: tabular-nums;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: .6rem;
        }

        footer {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: .84rem;
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
            <a id="new-analysis-link" href="index.php?new=1#job-entry" aria-current="page">New analysis</a>
            <a href="history.php">Analysis history</a>
            <a href="health.php">System health</a>
        </nav>

    </div>
</header>

<main id="main">

    <section class="intro">
        <p class="eyebrow">Production planning / Sequence analysis</p>

        <h1>Production changeover analysis</h1>

        <p class="lede">
            Compare the entered job order with one grouped by product family.
            Estimate setup time using a fixed setup-time assumption for each
            change between families.
        </p>
    </section>

    <?php if ($message != "") { ?>
        <div
            class="notice notice-success"
            id="save-message"
            role="status"
            aria-live="polite"
        >
            <strong>Analysis saved.</strong>
            <p><?php echo escapeHtml($message); ?></p>
        </div>
    <?php } ?>

    <?php if ($error != "") { ?>
        <div class="notice notice-error" role="alert" aria-live="assertive">
            <strong>Review the job details.</strong>
            <p><?php echo escapeHtml($error); ?></p>
        </div>
    <?php } ?>

    <div class="layout-grid">

        <section class="panel" id="job-entry">

            <div class="panel-heading">
                <div>
                    <p class="section-label">01 / Input</p>
                    <h2>Production job list</h2>
                </div>
            </div>

            <div class="panel-body">

                <p>
                    Enter one production job per line. The original sequence
                    follows the order entered in the box.
                </p>

                <form method="POST" action="index.php">

                    <div class="field">
                        <label for="jobs">
                            Job code, product family, processing time
                        </label>

                        <textarea
                            id="jobs"
                            name="jobs"
                            rows="8"
                            spellcheck="false"
                            aria-describedby="jobs-help"
                            required><?php echo escapeHtml($jobsText); ?></textarea>

                        <span class="help" id="jobs-help">
                            Use three comma-separated values per line.
                            Processing time must be a positive whole number.
                        </span>
                    </div>

                    <div class="field">
                        <label for="setup_minutes">
                            Setup time per family change (minutes)
                        </label>

                        <input
                            type="number"
                            id="setup_minutes"
                            name="setup_minutes"
                            min="0"
                            max="10000"
                            step="1"
                            inputmode="numeric"
                            value="<?php echo escapeHtml($setupMinutes); ?>"
                            aria-describedby="setup-help"
                            required>

                        <span class="help" id="setup-help">
                            Enter a non-negative whole number. Zero is allowed.
                            Use an estimate appropriate for your example or scenario.
                        </span>
                    </div>

                    <div class="button-row">

                        <button
                            class="primary-button"
                            type="submit"
                        >
                            Run analysis and save
                        </button>

                        <button
                            class="secondary-button"
                            type="button"
                            id="load-example"
                        >
                            Load example data
                        </button>

                    </div>

                    <p class="help submit-help">
                        After a successful analysis, this page will take you
                        directly to the comparison results below.
                    </p>

                    <p
                        class="example-status"
                        id="example-status"
                        role="status"
                        aria-live="polite"
                    ></p>

                </form>

            </div>
        </section>

        <aside class="panel guide-panel" aria-labelledby="input-guide-title">

            <div class="panel-heading">
                <div>
                    <p class="section-label">Input guide</p>
                    <h2 id="input-guide-title">Quick input reference</h2>
                </div>
            </div>

            <div class="panel-body">

                <p>
                    Enter one job per line. Each line contains a job code,
                    product family, and processing time in minutes.
                </p>

                <p class="note">
                    Format: <code>Job code,Family,Minutes</code>
                </p>

                <pre class="format-example">J1,A,30
J2,B,45</pre>

                <ul class="guide-quick-list">
                    <li>Job codes must be unique; product families cannot be blank.</li>
                    <li>Processing time must be a positive whole number.</li>
                    <li>Setup time must be zero or a positive whole number.</li>
                    <li>Enter up to 100 jobs per analysis.</li>
                </ul>

                <details class="full-guide">
                    <summary>View full input guide and calculation example</summary>

                    <div class="guide-details">

                        <section class="guide-section">
                            <h3>1. Enter the job information</h3>

                            <p>
                                Each line represents one production job. Enter its
                                unique job code, product family, and processing time
                                in minutes.
                            </p>

                            <ul>
                                <li>Job codes must be unique, cannot be blank, and cannot exceed 50 characters.</li>
                                <li>Product family labels cannot be blank or exceed 50 characters.</li>
                                <li>Processing time must be a positive whole number.</li>
                                <li>The application accepts up to 100 jobs per analysis.</li>
                            </ul>

                            <p class="note">
                                The Load example data button fills the form with six
                                illustrative jobs and a 10-minute setup assumption.
                                Review or edit those values before running the analysis.
                            </p>
                        </section>

                        <section class="guide-section">
                            <h3>2. Understand the original sequence</h3>

                            <p>
                                The original sequence is the order in which you enter
                                your jobs. A change occurs whenever two consecutive
                                jobs have different product families.
                            </p>

                            <div class="mini-sequence">A → B → A → C</div>

                            <p class="note">
                                This example has three family changes:
                                A to B, B to A, and A to C.
                            </p>
                        </section>

                        <section class="guide-section">
                            <h3>3. Understand the grouped sequence</h3>

                            <p>
                                The tool groups families in the order in which they
                                first appear. Jobs within each family retain their
                                original order.
                            </p>

                            <div class="mini-sequence">
                                Original: A → B → A → C<br>
                                Grouped: A → A → B → C
                            </div>

                            <p class="note">
                                This is a simple grouping method, not a search for the
                                mathematically optimal schedule.
                            </p>
                        </section>

                        <section class="guide-section">
                            <h3>4. Understand the calculation</h3>

                            <p>
                                The setup time you enter is applied once for each
                                change between consecutive product families.
                            </p>

                            <div class="formula">
                                Setup time = Family changes × Setup time per change
                            </div>

                            <p>
                                For the illustrative six-job dataset, the original
                                sequence has five changes and the grouped sequence
                                has two. At 10 minutes per change:
                            </p>

                            <div class="formula">
                                Original: 5 × 10 = 50 min<br>
                                Grouped: 2 × 10 = 20 min
                            </div>

                            <p>
                                Total estimated production time is the processing
                                time of all jobs plus the estimated setup time.
                            </p>
                        </section>

                        <section class="guide-section">
                            <h3>5. Interpret the results carefully</h3>

                            <ul>
                                <li>
                                    <strong>Processing time:</strong>
                                    the sum of all job processing times.
                                </li>
                                <li>
                                    <strong>Setup-time difference:</strong>
                                    original setup time minus grouped setup time.
                                </li>
                                <li>
                                    <strong>Total production time:</strong>
                                    processing time plus estimated setup time.
                                </li>
                            </ul>

                            <p class="note">
                                A positive setup-time difference indicates a lower
                                estimate for the grouped sequence. This is an
                                illustrative planning tool, not a guarantee of real
                                factory savings. It does not account for machine
                                constraints, deadlines, staffing, or measured
                                changeover times.
                            </p>
                        </section>

                    </div>
                </details>

            </div>
        </aside>

    </div>

    <?php if ($result !== null) { ?>

        <section
            class="panel"
            id="analysis-results"
            aria-labelledby="results-title"
        >

            <div class="panel-heading">
                <div>
                    <p class="section-label">02 / Results</p>
                    <h2 id="results-title" tabindex="-1">Sequence comparison</h2>
                </div>

                <span class="run-id">
                    Run #<?php echo escapeHtml($result["run_id"]); ?>
                </span>
            </div>

            <div class="panel-body">

                <div class="metrics">

                    <div class="metric">
                        <span class="metric-label">Original setup time</span>
                        <strong>
                            <?php echo escapeHtml($result["baseline_setup"]); ?>
                            <small>min</small>
                        </strong>
                    </div>

                    <div class="metric metric-good">
                        <span class="metric-label">Grouped setup time</span>
                        <strong>
                            <?php echo escapeHtml($result["grouped_setup"]); ?>
                            <small>min</small>
                        </strong>
                    </div>

                    <div class="metric">
                        <span class="metric-label">Processing time</span>
                        <strong>
                            <?php echo escapeHtml($result["processing_total"]); ?>
                            <small>min</small>
                        </strong>
                    </div>

                    <div class="metric">
                        <span class="metric-label">Jobs analysed</span>
                        <strong>
                            <?php echo escapeHtml(count($result["jobs"])); ?>
                        </strong>
                    </div>

                </div>

                <div class="sequence-grid">

                    <section class="sequence-card sequence-card-original">
                        <div class="sequence-title">
                            <h3>Original family sequence</h3>
                            <span class="count-label">
                                <?php echo escapeHtml($result["baseline_changes"]); ?>
                                changes
                            </span>
                        </div>

                        <code class="sequence-string"><?php
                            echo escapeHtml(
                                familySequenceText($result["baseline_jobs"])
                            );
                        ?></code>

                        <p class="sequence-meta">
                            Jobs remain in their entered order.
                        </p>
                    </section>

                    <section class="sequence-card sequence-card-grouped">
                        <div class="sequence-title">
                            <h3>Grouped family sequence</h3>
                            <span class="count-label">
                                <?php echo escapeHtml($result["grouped_changes"]); ?>
                                changes
                            </span>
                        </div>

                        <code class="sequence-string"><?php
                            echo escapeHtml(
                                familySequenceText($result["grouped_jobs"])
                            );
                        ?></code>

                        <p class="sequence-meta">
                            Jobs are grouped by family while preserving
                            the order within each family.
                        </p>
                    </section>

                </div>

                <p class="total-line">
                    <span>Original estimated production time</span>
                    <strong>
                        <?php echo escapeHtml($result["baseline_total"]); ?> min
                    </strong>
                </p>

                <p class="total-line">
                    <span>Grouped estimated production time</span>
                    <strong>
                        <?php echo escapeHtml($result["grouped_total"]); ?> min
                    </strong>
                </p>

                <div class="effect-note">

                    <?php if ($result["difference"] > 0) { ?>

                        <strong>
                            Estimated setup-time reduction:
                            <?php echo escapeHtml($result["difference"]); ?>
                            minutes.
                        </strong>

                        <p>
                            Under the selected assumptions, the grouped
                            sequence needs less setup time.
                        </p>

                    <?php } elseif ($result["difference"] == 0) { ?>

                        <strong>No estimated setup-time change.</strong>

                        <p>
                            Both sequences have the same estimated setup time.
                        </p>

                    <?php } else { ?>

                        <strong>
                            Estimated setup-time increase:
                            <?php echo escapeHtml(abs($result["difference"])); ?>
                            minutes.
                        </strong>

                        <p>
                            Under the selected assumptions, the grouped
                            sequence needs more setup time.
                        </p>

                    <?php } ?>

                </div>

                <p class="note">
                    These are estimates based on the family-change count and
                    the setup-time value entered. They are not a guarantee of
                    actual factory performance.
                </p>

            </div>
        </section>

        <div class="layout-grid" id="analysis-detail-tables">

            <section class="panel">

                <div class="panel-heading">
                    <div>
                        <p class="section-label">03 / Baseline</p>
                        <h2>Original job order</h2>
                    </div>

                    <span class="count-label">
                        <?php echo escapeHtml($result["baseline_changes"]); ?>
                        family changes
                    </span>
                </div>

                <div class="panel-body">

                    <div
                        class="table-wrap"
                        tabindex="0"
                        role="region"
                        aria-label="Original job order table"
                    >

                        <table>
                            <caption>Jobs in the order entered</caption>

                            <thead>
                                <tr>
                                    <th scope="col">Position</th>
                                    <th scope="col">Job code</th>
                                    <th scope="col">Product family</th>
                                    <th scope="col">Processing time</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $position = 1;

                                foreach ($result["baseline_jobs"] as $job) {
                                ?>
                                    <tr>
                                        <td><?php echo $position; ?></td>
                                        <td><?php echo escapeHtml($job["job_code"]); ?></td>
                                        <td><?php echo escapeHtml($job["family"]); ?></td>
                                        <td><?php echo escapeHtml($job["processing"]); ?> min</td>
                                    </tr>
                                <?php
                                    $position++;
                                }
                                ?>
                            </tbody>

                        </table>

                    </div>

                    <p class="total-line">
                        <span>Estimated production time</span>
                        <strong>
                            <?php echo escapeHtml($result["baseline_total"]); ?> min
                        </strong>
                    </p>

                </div>
            </section>

            <section class="panel">

                <div class="panel-heading">
                    <div>
                        <p class="section-label">04 / Grouped</p>
                        <h2>Grouped job order</h2>
                    </div>

                    <span class="count-label">
                        <?php echo escapeHtml($result["grouped_changes"]); ?>
                        family changes
                    </span>
                </div>

                <div class="panel-body">

                    <div
                        class="table-wrap"
                        tabindex="0"
                        role="region"
                        aria-label="Grouped job order table"
                    >

                        <table>
                            <caption>Jobs grouped by product family</caption>

                            <thead>
                                <tr>
                                    <th scope="col">Position</th>
                                    <th scope="col">Job code</th>
                                    <th scope="col">Product family</th>
                                    <th scope="col">Processing time</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $position = 1;

                                foreach ($result["grouped_jobs"] as $job) {
                                ?>
                                    <tr>
                                        <td><?php echo $position; ?></td>
                                        <td><?php echo escapeHtml($job["job_code"]); ?></td>
                                        <td><?php echo escapeHtml($job["family"]); ?></td>
                                        <td><?php echo escapeHtml($job["processing"]); ?> min</td>
                                    </tr>
                                <?php
                                    $position++;
                                }
                                ?>
                            </tbody>

                        </table>

                    </div>

                    <p class="total-line">
                        <span>Estimated production time</span>
                        <strong>
                            <?php echo escapeHtml($result["grouped_total"]); ?> min
                        </strong>
                    </p>

                </div>
            </section>

        </div>

    <?php } ?>

    <section class="panel">

        <div class="panel-heading">
            <div>
                <p class="section-label">05 / Records</p>
                <h2>Analysis records and system checks</h2>
            </div>
        </div>

        <div class="panel-body actions">
            <a class="link-button" href="history.php">
                View analysis history
            </a>

            <a class="link-button" href="health.php">
                Check system health
            </a>
        </div>

    </section>

    <footer>
        <p>
            Planning tool for estimating changeover time. Results depend on
            the job list and the fixed setup-time assumption entered.
        </p>
    </footer>

</main>

<?php if ($result !== null) { ?>
<script>
(function () {
    "use strict";

    const resultsSection = document.getElementById("analysis-results");
    const resultsHeading = document.getElementById("results-title");

    if (!resultsSection || !resultsHeading) {
        return;
    }

    resultsSection.scrollIntoView({
        behavior: "auto",
        block: "start"
    });

    resultsHeading.focus({
        preventScroll: true
    });
})();
</script>
<?php } ?>

<script>
(function () {
    "use strict";

    const exampleButton = document.getElementById("load-example");
    const jobsField = document.getElementById("jobs");
    const setupField = document.getElementById("setup_minutes");
    const exampleStatus = document.getElementById("example-status");
	
	const newAnalysisLink = document.getElementById("new-analysis-link");

newAnalysisLink.addEventListener("click", function (event) {
    const targetUrl = new URL(
        newAnalysisLink.href,
        window.location.href
    );

    const currentUrl = new URL(window.location.href);

    const sameAddress =
        currentUrl.pathname === targetUrl.pathname &&
        currentUrl.search === targetUrl.search &&
        currentUrl.hash === targetUrl.hash;

    // If the URL differs, let normal navigation load a fresh page.
    if (!sameAddress) {
        return;
    }

    // The browser is already at the New analysis URL.
    // Clear the current form without reloading the page.
    event.preventDefault();

    jobsField.value = "";
    setupField.value = "";
    exampleStatus.textContent = "";

    // Hide all previous results and messages.
    const oldResults =
        document.getElementById("analysis-results");

    const oldDetailTables =
        document.getElementById("analysis-detail-tables");

    const oldMessage =
        document.getElementById("save-message");

    const oldError =
        document.querySelector(".notice-error");

    if (oldResults) {
        oldResults.hidden = true;
    }

    if (oldDetailTables) {
        oldDetailTables.hidden = true;
    }

    if (oldMessage) {
        oldMessage.hidden = true;
    }

    if (oldError) {
        oldError.hidden = true;
    }

    // Return to the input section.
    const inputSection =
        document.getElementById("job-entry");

    if (inputSection) {
        inputSection.scrollIntoView({
            behavior: "auto",
            block: "start"
        });
    }
});

    // Take control of scroll restoration so the browser does not
// put the page back at the old bottom-of-page position.
if ("scrollRestoration" in window.history) {
    window.history.scrollRestoration = "manual";
}

window.addEventListener("pageshow", function (event) {
    const navigationEntries =
        window.performance.getEntriesByType("navigation");

    const navigationType = navigationEntries.length > 0
        ? navigationEntries[0].type
        : "";

    const returnedViaHistory =
        event.persisted || navigationType === "back_forward";

    if (!returnedViaHistory) {
        return;
    }

    // Clear values restored by browser Back/Forward navigation.
    jobsField.value = "";
    setupField.value = "";
    exampleStatus.textContent = "";

    // Hide every part of the previous analysis.
    const oldResults = document.getElementById("analysis-results");
    const oldDetailTables =
        document.getElementById("analysis-detail-tables");
    const oldMessage = document.getElementById("save-message");
    const oldError = document.querySelector(".notice-error");

    if (oldResults) {
        oldResults.hidden = true;
    }

    if (oldDetailTables) {
        oldDetailTables.hidden = true;
    }

    if (oldMessage) {
        oldMessage.hidden = true;
    }

    if (oldError) {
        oldError.hidden = true;
    }

    // Wait until the browser has completed its restoration work,
    // then deliberately return to the input section.
    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
            const inputSection = document.getElementById("job-entry");

            if (inputSection) {
                inputSection.scrollIntoView({
                    behavior: "auto",
                    block: "start"
                });
            }
        });
    });
});

    const exampleJobs = [
        "J1,A,30",
        "J2,B,45",
        "J3,A,25",
        "J4,C,40",
        "J5,B,35",
        "J6,A,20"
    ].join("\n");

    exampleButton.addEventListener("click", function () {
        jobsField.value = exampleJobs;
        setupField.value = "10";

        /*
         * Avoid showing results from an earlier submission
         * beside newly loaded example input.
         * The earlier saved record remains in the history.
         */
        const oldResults = document.getElementById("analysis-results");
        const oldDetailTables =
            document.getElementById("analysis-detail-tables");
        const oldMessage = document.getElementById("save-message");
        const oldError = document.querySelector(".notice-error");

        if (oldResults) {
            oldResults.hidden = true;
        }

        if (oldDetailTables) {
            oldDetailTables.hidden = true;
        }

        if (oldMessage) {
            oldMessage.hidden = true;
        }

        if (oldError) {
            oldError.hidden = true;
        }

        exampleStatus.textContent =
            "Example data loaded. Review the jobs and setup time, then run the analysis.";

        jobsField.focus();
    });
})();
</script>

</body>
</html>