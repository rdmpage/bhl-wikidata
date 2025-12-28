<?php

/**
 * Background worker to process queued jobs
 *
 * Usage:
 *   php worker.php              - Process up to 10 pending jobs then exit
 *   php worker.php --limit=50   - Process up to 50 pending jobs then exit
 *   php worker.php --daemon     - Run continuously, polling for new jobs
 */

require_once(dirname(__FILE__) . '/shared.php');
require_once(dirname(__FILE__) . '/wikidata.php');
require_once(dirname(__FILE__) . '/lib/Queue.php');

// Parse command line arguments
$limit = 10;
$daemon = false;

foreach ($argv as $arg) {
    if (preg_match('/--limit=(\d+)/', $arg, $m)) {
        $limit = intval($m[1]);
    }
    if ($arg === '--daemon') {
        $daemon = true;
    }
}

echo "[worker] Starting worker (limit=$limit, daemon=" . ($daemon ? 'yes' : 'no') . ")\n";

// Initialize queue
$queue = new Queue();

// Main worker loop
do {
    // Reset any stale jobs (stuck in processing state for > 5 minutes)
    $reset_count = $queue->resetStaleJobs(300);
    if ($reset_count > 0) {
        echo "[worker] Reset $reset_count stale job(s)\n";
    }

    // Get pending jobs
    $jobs = $queue->getPendingJobs($limit);
    $job_count = count($jobs);

    if ($job_count === 0) {
        if ($daemon) {
            echo "[worker] No pending jobs, sleeping...\n";
            sleep(10); // Wait 10 seconds before checking again
            continue;
        } else {
            echo "[worker] No pending jobs, exiting\n";
            break;
        }
    }

    echo "[worker] Processing $job_count job(s)...\n";

    foreach ($jobs as $job) {
        process_job($queue, $job);
    }

    // If not in daemon mode, exit after processing this batch
    if (!$daemon) {
        break;
    }

} while (true);

echo "[worker] Worker finished\n";

/**
 * Process a single job
 */
function process_job($queue, $job)
{
    $job_id = $job['id'];
    $pid = $job['pid'];
    $pid_type = $job['pid_type'];

    echo "[worker] Processing job #{$job_id}: {$pid_type}={$pid}\n";

    // Mark as processing
    $queue->markJobProcessing($job_id);

    try {
        $result = null;

        // Route to appropriate handler based on identifier type
        switch ($pid_type) {
            case 'doi':
                $result = add_from_doi($pid);
                break;

            // Add more identifier types here as needed
            // case 'pmid':
            //     $result = add_from_pmid($pid);
            //     break;

            default:
                throw new Exception("Unsupported identifier type: {$pid_type}");
        }

        // Check if we got a result
        if ($result === null || $result === '') {
            // No result - might be already in Wikidata or identifier not found
            $queue->markJobCompleted($job_id, '');
            echo "[worker]   -> No result (may already exist or not found)\n";
        } else {
            // Success - save the quickstatements
            $queue->markJobCompleted($job_id, $result);
            echo "[worker]   -> Completed successfully\n";
        }

    } catch (Exception $e) {
        // Error occurred
        $error = $e->getMessage();
        $queue->markJobFailed($job_id, $error);
        echo "[worker]   -> Failed: $error\n";
    }
}
