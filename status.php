<?php

/**
 * AJAX endpoint to check batch status
 *
 * Usage: status.php?batch_id=batch_xxxxx
 *
 * Returns JSON with batch progress and job details
 */

header('Content-Type: application/json');

require_once(dirname(__FILE__) . '/lib/Queue.php');

// Get batch_id from request
$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : '';

if ($batch_id === '') {
    echo json_encode([
        'error' => 'Missing batch_id parameter'
    ]);
    exit;
}

try {
    $queue = new Queue();
    $status = $queue->getBatchStatus($batch_id);

    if ($status === null) {
        echo json_encode([
            'error' => 'Batch not found'
        ]);
        exit;
    }

    $batch = $status['batch'];
    $jobs = $status['jobs'];

    // Calculate progress
    $total = $batch['total_jobs'];
    $completed = $batch['completed_jobs'];
    $failed = $batch['failed_jobs'];
    $pending = 0;
    $processing = 0;

    foreach ($jobs as $job) {
        if ($job['status'] === 'pending') {
            $pending++;
        } else if ($job['status'] === 'processing') {
            $processing++;
        }
    }

    $finished = $completed + $failed;
    $progress_percent = $total > 0 ? round(($finished / $total) * 100) : 0;
    $is_complete = ($finished >= $total);

    // Collect results for completed jobs
    $results = [];
    $existing_items = [];
    $errors = [];

    foreach ($jobs as $job) {
        if ($job['status'] === 'completed' && $job['result'] && $job['result'] !== '') {
            $results[] = [
                'pid' => $job['pid'],
                'pid_type' => $job['pid_type'],
                'result' => $job['result']
            ];
        } else if ($job['status'] === 'completed' && (!$job['result'] || $job['result'] === '')) {
            // Empty result usually means item already exists
            $existing_items[] = [
                'pid' => $job['pid'],
                'pid_type' => $job['pid_type']
            ];
        } else if ($job['status'] === 'failed') {
            $errors[] = [
                'pid' => $job['pid'],
                'pid_type' => $job['pid_type'],
                'error' => $job['error']
            ];
        }
    }

    // Return status
    echo json_encode([
        'batch_id' => $batch_id,
        'total' => $total,
        'completed' => $completed,
        'failed' => $failed,
        'pending' => $pending,
        'processing' => $processing,
        'progress_percent' => $progress_percent,
        'is_complete' => $is_complete,
        'results' => $results,
        'existing_items' => $existing_items,
        'errors' => $errors,
        'created_at' => $batch['created_at']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Internal error: ' . $e->getMessage()
    ]);
}
