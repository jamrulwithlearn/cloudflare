<?php

class CloudflareFileDB
{
    private PDO $db;

    public function __construct(string $dbPath = __DIR__ . '/r2_files.sqlite')
    {
        $this->db = new PDO("sqlite:" . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initTable();
    }

    private function initTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(255) NULL,
            file_key VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_url TEXT NULL,
            file_size INTEGER NULL,
            file_type VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
    }

    /**
     * Insert Record
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO files (title, file_key, file_name, file_url, file_size, file_type)
            VALUES (:title, :file_key, :file_name, :file_url, :file_size, :file_type)
        ");

        $stmt->execute([
            ':title'     => $data['title'] ?? null,
            ':file_key'  => $data['file_key'],
            ':file_name' => $data['file_name'],
            ':file_url'  => $data['file_url'] ?? '',
            ':file_size' => $data['file_size'] ?? 0,
            ':file_type' => $data['file_type'] ?? '',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update Record
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE files 
            SET title = :title,
                file_key = :file_key,
                file_name = :file_name,
                file_url = :file_url,
                file_size = :file_size,
                file_type = :file_type
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id'        => $id,
            ':title'     => $data['title'] ?? null,
            ':file_key'  => $data['file_key'],
            ':file_name' => $data['file_name'],
            ':file_url'  => $data['file_url'] ?? '',
            ':file_size' => $data['file_size'] ?? 0,
            ':file_type' => $data['file_type'] ?? '',
        ]);
    }

    /**
     * Delete Record
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM files WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get Single File Record By ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM files WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get All Files
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM files ORDER BY id DESC");
        return $stmt->fetchAll();
    }
}