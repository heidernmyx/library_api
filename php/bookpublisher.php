<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

include "../php/connection/connection.php";
require_once 'logs.php';
require_once 'notification.php';

class BookPublisher {
    private $pdo;
    private $logs;
    private $notification;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->logs = new Logs($pdo);
        $this->notification = new Notification($pdo);
    }

    private function getUsersName($userId) {
        $stmt = $this->pdo->prepare("SELECT Fname FROM users WHERE UserID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $user['Fname'] : 'Unknown User';
    }

    public function fetchPublishers() {
        $stmt = $this->pdo->prepare("
       SELECT
            `PublisherID`,
            `PublisherName`,
            contacts.ContactID,
            contacts.Phone,
            contacts.Email,
            addresses.AddressID,
            addresses.Street,
            addresses.City,
            addresses.State,
            addresses.Country,
            addresses.PostalCode,
            `IsActive`
        FROM
            `publisher`
        LEFT JOIN contacts ON publisher.ContactID  = contacts.ContactID
        LEFT JOIN addresses ON publisher.AddressID = addresses.AddressID
        ");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($result);
    }

    public function addPublisher($json) {
        try {
            $this->pdo->beginTransaction();

            // Check if email already exists in contacts
            $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
            $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'Email already exists.']);
                exit;
            }

            // Check if the publisher name already exists
            unset($stmt);
            $stmt = $this->pdo->prepare("SELECT * FROM publisher WHERE PublisherName = :publisherName");
            $stmt->bindParam(':publisherName', $json['PublisherName'], PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'Publisher already exists.']);
                exit;
            }

            // Insert into contacts
            $sql = "INSERT INTO `contacts` (`Phone`, `Email`) VALUES (:phone, :email)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':phone', $json['Phone'], PDO::PARAM_STR);
            $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
            $stmt->execute();
            $contactId = $this->pdo->lastInsertId();

            // Insert into addresses
            unset($stmt);
            $sql = "INSERT INTO `addresses` (`Street`, `City`, `State`, `Country`, `PostalCode`) 
                    VALUES (:street, :city, :state, :country, :postalCode)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':street', $json['Street'], PDO::PARAM_STR);
            $stmt->bindParam(':city', $json['City'], PDO::PARAM_STR);
            $stmt->bindParam(':state', $json['State'], PDO::PARAM_STR);
            $stmt->bindParam(':country', $json['Country'], PDO::PARAM_STR);
            $stmt->bindParam(':postalCode', $json['PostalCode'], PDO::PARAM_STR);
            $stmt->execute();
            $addressId = $this->pdo->lastInsertId();

            // Insert into publisher
            unset($stmt);
            $sql = "INSERT INTO `publisher` (`PublisherName`, `ContactID`, `AddressID`) VALUES (?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$json['PublisherName'], $contactId, $addressId]);

            // Commit transaction
            $this->pdo->commit();

            // Get user details for logs and notification
            $userId = intval($json['user_id']);
            $userName = $this->getUsersName($userId);

            // Add notification for librarians
            $this->notification->addNotificationForLibrarians("'{$json['PublisherName']}' has been added", 18);

            // Log the action
            $this->logs->addLogs($userId, "$userName added a Book Publisher: '{$json['PublisherName']}'");

            http_response_code(201); 
            echo json_encode(['success' => true, 'message' => 'Book Publisher added successfully.']);

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Adding of book publisher failed: ' . $e->getMessage()]);
        }
    }
public function updatePublisher($json) {
    try {
        $this->pdo->beginTransaction();
        
        // Log the incoming data
        file_put_contents('update_log.txt', print_r($json, true), FILE_APPEND);

        // Check if the email already exists for another contact (different ContactID)
        $stmt = $this->pdo->prepare("
            SELECT ContactID FROM contacts 
            WHERE Email = :email AND ContactID != :contactId
        ");
        $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
        $stmt->bindParam(':contactId', $json['ContactID'], PDO::PARAM_INT);
        $stmt->execute();

        // Log the query result
        file_put_contents('update_log.txt', "Email check result: " . print_r($stmt->fetch(), true), FILE_APPEND);

        // If the email exists for another ContactID, throw an exception
        if ($stmt->fetch()) {
            throw new Exception('Email already exists for another contact.');
        }

        // Proceed with updating contact details
        $stmt = $this->pdo->prepare("
            UPDATE contacts 
            SET Phone = :phone, Email = :email 
            WHERE ContactID = :contactId
        ");
        $stmt->bindParam(':phone', $json['Phone'], PDO::PARAM_STR);
        $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
        $stmt->bindParam(':contactId', $json['ContactID'], PDO::PARAM_INT);
        $stmt->execute();

        // Log the contact update
        file_put_contents('update_log.txt', "Updated contact ID: " . $json['ContactID'], FILE_APPEND);

        // Update address details
        unset($stmt);
        $stmt = $this->pdo->prepare("
            UPDATE addresses 
            SET Street = :street, City = :city, State = :state, Country = :country, PostalCode = :postalCode 
            WHERE AddressID = :addressId
        ");
        $stmt->bindParam(':street', $json['Street'], PDO::PARAM_STR);
        $stmt->bindParam(':city', $json['City'], PDO::PARAM_STR);
        $stmt->bindParam(':state', $json['State'], PDO::PARAM_STR);
        $stmt->bindParam(':country', $json['Country'], PDO::PARAM_STR);
        $stmt->bindParam(':postalCode', $json['PostalCode'], PDO::PARAM_STR);
        $stmt->bindParam(':addressId', $json['AddressID'], PDO::PARAM_INT);
        $stmt->execute();

        // Log address update
        file_put_contents('update_log.txt', "Updated address", FILE_APPEND);

        // Update publisher name
        unset($stmt);
        $stmt = $this->pdo->prepare("
            UPDATE publisher 
            SET PublisherName = :publisherName 
            WHERE PublisherID = :publisherId
        ");
        $stmt->bindParam(':publisherName', $json['PublisherName'], PDO::PARAM_STR);
        $stmt->bindParam(':publisherId', $json['PublisherID'], PDO::PARAM_INT);
        $stmt->execute();

        // Commit transaction
        $this->pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Publisher updated successfully.']);

    } catch (Exception $e) {
        $this->pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update publisher: ' . $e->getMessage()]);
    }
}


    public function archivePublishers($json) {
      
    }
}

if ($_SERVER['REQUEST_METHOD'] == "GET") {
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $operation = $_POST['operation'];
    $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
}

$bookPublisher = new BookPublisher($pdo);
switch ($operation) {
    case 'fetchPublishers':
        $bookPublisher->fetchPublishers();
        break;
    case 'addPublisher':
        $bookPublisher->addPublisher($json);
        break;
    case 'updatePublisher':
        $bookPublisher->updatePublisher($json);
        break;
    case 'deletePublisher':
        $bookPublisher->archivePublishers($json);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}
// POST request with form data

// Key: operation
// Value: addPublisher

// Key: json
// Value: { 
//   "Email": "BastaPublisher@gmail.com", 
//   "Phone": "01233210", 
//   "Street" : "Sa amoa Street", 
//   "City": "Cagayan de Oro", 
//   "State": "Misamis Oriental", 
//   "Country": "Philippines", 
//   "PostalCode": "9000", 
//   "PublisherName": "Basta Publisher" 
// }
