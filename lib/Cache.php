<?php

/**
 * Simple SQLite-based cache
 */
class Cache
{
    private $db;
    private $default_ttl = 86400; // 24 hours

    public function __construct($db_path = null)
    {
        if ($db_path === null) {
            $db_path = dirname(__FILE__) . '/../db/cache.db';
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
     * Get a value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Returns cached value or null if not found/expired
     */
    public function get($key)
    {
        $stmt = $this->db->prepare(
            "SELECT cache_value, expires_at FROM cache WHERE cache_key = :key"
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Check if expired
        if ($row['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }

        return $row['cache_value'];
    }

    /**
     * Set a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache (will be converted to string)
     * @param int $ttl Time to live in seconds (default: 24 hours)
     */
    public function set($key, $value, $ttl = null)
    {
        if ($ttl === null) {
            $ttl = $this->default_ttl;
        }

        $expires_at = time() + $ttl;

        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO cache (cache_key, cache_value, expires_at)
             VALUES (:key, :value, :expires_at)"
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':expires_at' => $expires_at
        ]);
    }

    /**
     * Delete a key from cache
     */
    public function delete($key)
    {
        $stmt = $this->db->prepare("DELETE FROM cache WHERE cache_key = :key");
        $stmt->execute([':key' => $key]);
    }

    /**
     * Clear expired entries
     *
     * @return int Number of entries deleted
     */
    public function clearExpired()
    {
        $stmt = $this->db->prepare("DELETE FROM cache WHERE expires_at < :now");
        $stmt->execute([':now' => time()]);

        return $stmt->rowCount();
    }

    /**
     * Clear all cache
     */
    public function clearAll()
    {
        $this->db->exec("DELETE FROM cache");
    }

    /**
     * Get cache statistics
     */
    public function getStats()
    {
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM cache");
        $total = $stmt->fetchColumn();

        $stmt = $this->db->query("SELECT COUNT(*) as expired FROM cache WHERE expires_at < " . time());
        $expired = $stmt->fetchColumn();

        return [
            'total' => $total,
            'valid' => $total - $expired,
            'expired' => $expired
        ];
    }
}
