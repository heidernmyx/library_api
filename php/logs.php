<?php
class Logs{
    private $pdo;
    public function __construct($pdo){
        $this->pdo = $pdo;
    }
    public function fetchLogs($user_id){
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    `LogID`,
                    users.Fname,
                    `Context`,
                    `Date`
                FROM
                    `logs`
                WHERE
                    UserID = ?
                ORDER BY Date DESC"
            );
            $stmt->execute([$user_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }catch (\PDOException $e){
            return [];
        }
    }
    public function addLogs($user_id, $context){
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `logs`(`UserID`, `Context`, `Date`) VALUES (?, ?, NOW())"
            );
            $stmt->execute([$user_id, $context]);
        }catch (\PDOException $e){
        }
    }


}