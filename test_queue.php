<?php

/**
 * Simple test script for queue and cache functionality
 */

require_once 'lib/Queue.php';
require_once 'lib/Cache.php';

echo "=== Testing Queue ===\n";

try {
    $queue = new Queue();
    echo "✓ Queue initialized\n";

    // Create test batch
    $items = [
        ['pid' => '10.5962/bhl.part.1', 'pid_type' => 'doi'],
        ['pid' => '10.5962/bhl.part.2', 'pid_type' => 'doi'],
        ['pid' => '10.5962/bhl.part.3', 'pid_type' => 'doi'],
    ];

    $batch_id = $queue->createBatch($items);
    echo "✓ Created batch: $batch_id\n";

    // Get pending jobs
    $jobs = $queue->getPendingJobs(10);
    echo "✓ Found " . count($jobs) . " pending jobs\n";

    if (count($jobs) > 0) {
        // Test marking a job as processing
        $job = $jobs[0];
        $queue->markJobProcessing($job['id']);
        echo "✓ Marked job #{$job['id']} as processing\n";

        // Test completing a job
        $queue->markJobCompleted($job['id'], 'TEST_RESULT');
        echo "✓ Marked job #{$job['id']} as completed\n";

        // Test failing a job
        if (count($jobs) > 1) {
            $job2 = $jobs[1];
            $queue->markJobProcessing($job2['id']);
            $queue->markJobFailed($job2['id'], 'TEST_ERROR');
            echo "✓ Marked job #{$job2['id']} as failed\n";
        }
    }

    // Get batch status
    $status = $queue->getBatchStatus($batch_id);
    if ($status) {
        $batch = $status['batch'];
        echo "✓ Batch status: {$batch['completed_jobs']} completed, {$batch['failed_jobs']} failed of {$batch['total_jobs']} total\n";
    }

    echo "\n";

} catch (Exception $e) {
    echo "✗ Queue test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Testing Cache ===\n";

try {
    $cache = new Cache();
    echo "✓ Cache initialized\n";

    // Test set/get
    $cache->set('test_key', 'test_value', 60);
    echo "✓ Set cache value\n";

    $value = $cache->get('test_key');
    if ($value === 'test_value') {
        echo "✓ Retrieved cache value: $value\n";
    } else {
        echo "✗ Cache value mismatch\n";
        exit(1);
    }

    // Test expiry
    $cache->set('expired_key', 'old_value', -1);
    $expired = $cache->get('expired_key');
    if ($expired === null) {
        echo "✓ Expired cache correctly returned null\n";
    } else {
        echo "✗ Expired cache should be null\n";
        exit(1);
    }

    // Test stats
    $stats = $cache->getStats();
    echo "✓ Cache stats: {$stats['valid']} valid entries\n";

    echo "\n";

} catch (Exception $e) {
    echo "✗ Cache test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== All Tests Passed ===\n";
