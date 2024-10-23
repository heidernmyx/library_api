<?php
class Logs{
    private $pdo;
    public function __construct($pdo){
        $this->pdo = $pdo;
    }
    public function fetchLogs(){
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    `LogID`,
                    users.Fname,
                    `Context`,
                    `Date`
                FROM
                    `logs`
                INNER JOIN
                    users ON logs.UserID = users.UserID
                ORDER BY
                    Date DESC"
            );
            $stmt->execute(); 
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