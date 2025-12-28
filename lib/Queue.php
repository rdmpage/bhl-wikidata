<?php

/**
 * Simple SQLite-based job queue
 */
class Queue
{
    private $db;

    public function __construct($db_path = null)
    {
        if ($db_path === null) {
            $db_path = dirname(__FILE__) . '/../db/queue.db';
        }

        // Ensure directory exists
        $dir = dirname($db_path);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        // Open/create database
        $this->db = new PDO('sqlite:' . $db_path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Initialize schema
        $this->initSchema();
    }

    private function initSchema()
    {
        $schema = file_get_contents(dirname(__FILE__) . '/../db/schema.sql');
        $this->db->exec($schema);
    }

    /**
     * Create a new batch of jobs
     *
     * @param array $items Array of ['pid' => '...', 'pid_type' => '...']
     * @return string The batch_id
     */
    public function createBatch($items)
    {
        $batch_id = $this->generateBatchId();
        $now = time();

        // Begin transaction
        $this->db->beginTransaction();

        try {
            // Create batch record
            $stmt = $this->db->prepare(
                "INSERT INTO job_batches (batch_id, total_jobs, completed_jobs, failed_jobs, created_at)
                 VALUES (:batch_id, :total, 0, 0, :created_at)"
            );
            $stmt->execute([
                ':batch_id' => $batch_id,
                ':total' => count($items),
                ':created_at' => $now
            ]);

            // Create job records
            $stmt = $this->db->prepare(
                "INSERT INTO jobs (pid, pid_type, batch_id, status, created_at, updated_at)
                 VALUES (:pid, :pid_type, :batch_id, 'pending', :created_at, :updated_at)"
            );

            foreach ($items as $item) {
                $stmt->execute([
                    ':pid' => $item['pid'],
                    ':pid_type' => $item['pid_type'],
                    ':batch_id' => $batch_id,
                    ':created_at' => $now,
                    ':updated_at' => $now
                ]);
            }

            $this->db->commit();

            return $batch_id;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get pending jobs (limit to avoid memory issues)
     *
     * @param int $limit Maximum number of jobs to return
     * @return array Array of job records
     */
    public function getPendingJobs($limit = 10)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a job as processing
     */
    public function markJobProcessing($job_id)
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'processing', updated_at = :updated_at WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $job_id,
            ':updated_at' => time()
        ]);
    }

    /**
     * Mark a job as completed
     */
    public function markJobCompleted($job_id, $result)
    {
        $now = time();

        $this->db->beginTransaction();

        try {
            // Update job
            $stmt = $this->db->prepare(
                "UPDATE jobs SET status = 'completed', result = :result,
                 updated_at = :updated_at, completed_at = :completed_at
                 WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $job_id,
                ':result' => $result,
                ':updated_at' => $now,
                ':completed_at' => $now
            ]);

            // Update batch counters
            $stmt = $this->db->prepare(
                "SELECT batch_id FROM jobs WHERE id = :id"
            );
            $stmt->execute([':id' => $job_id]);
            $batch_id = $stmt->fetchColumn();

            if ($batch_id) {
                $this->updateBatchCounters($batch_id);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a job as failed
     */
    public function markJobFailed($job_id, $error)
    {
        $now = time();

        $this->db->beginTransaction();

        try {
            // Update job
            $stmt = $this->db->prepare(
                "UPDATE jobs SET status = 'failed', error = :error,
                 updated_at = :updated_at, completed_at = :completed_at
                 WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $job_id,
                ':error' => $error,
                ':updated_at' => $now,
                ':completed_at' => $now
            ]);

            // Update batch counters
            $stmt = $this->db->prepare(
                "SELECT batch_id FROM jobs WHERE id = :id"
            );
            $stmt->execute([':id' => $job_id]);
            $batch_id = $stmt->fetchColumn();

            if ($batch_id) {
                $this->updateBatchCounters($batch_id);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get batch status
     */
    public function getBatchStatus($batch_id)
    {
        // Get batch info
        $stmt = $this->db->prepare(
            "SELECT * FROM job_batches WHERE batch_id = :batch_id"
        );
        $stmt->execute([':batch_id' => $batch_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            return null;
        }

        // Get jobs for this batch
        $stmt = $this->db->prepare(
            "SELECT * FROM jobs WHERE batch_id = :batch_id ORDER BY id ASC"
        );
        $stmt->execute([':batch_id' => $batch_id]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'batch' => $batch,
            'jobs' => $jobs
        ];
    }

    /**
     * Update batch counters
     */
    private function updateBatchCounters($batch_id)
    {
        $stmt = $this->db->prepare(
            "UPDATE job_batches SET
             completed_jobs = (SELECT COUNT(*) FROM jobs WHERE batch_id = :batch_id AND status = 'completed'),
             failed_jobs = (SELECT COUNT(*) FROM jobs WHERE batch_id = :batch_id AND status = 'failed')
             WHERE batch_id = :batch_id"
        );
        $stmt->execute([':batch_id' => $batch_id]);
    }

    /**
     * Generate a unique batch ID
     */
    private function generateBatchId()
    {
        return 'batch_' . time() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Reset stale processing jobs (jobs stuck in processing state)
     * Call this periodically to handle crashes
     *
     * @param int $timeout Seconds after which a processing job is considered stale
     */
    public function resetStaleJobs($timeout = 300)
    {
        $cutoff = time() - $timeout;

        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'pending', updated_at = :now
             WHERE status = 'processing' AND updated_at < :cutoff"
        );
        $stmt->execute([
            ':now' => time(),
            ':cutoff' => $cutoff
        ]);

        return $stmt->rowCount();
    }
}
