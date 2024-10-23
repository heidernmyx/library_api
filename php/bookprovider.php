<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

include '../php/connection/connection.php';
require_once 'logs.php';
require_once 'notification.php';
class BookProvider {
  private $pdo;
  private $logs;
  private $notification;

  public function __construct($pdo) {
    $this->pdo = $pdo;
    $this->logs = new Logs($pdo);
    $this->notification = new Notification($pdo);
  }

   private function getUsersName($userId){
        $stmt = $this->pdo->prepare("SELECT Fname FROM users WHERE UserID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $user['Fname'] : 'Unknown User';
    }

public function fetchBookProviders() {
    $stmt = $this->pdo->prepare("
    SELECT
        ProviderID,
        contacts.ContactID,
        addresses.AddressID,
        ProviderName,
        contacts.Phone,
        contacts.Email,
        addresses.Street,
        addresses.City,
        addresses.State,
        addresses.Country,
        addresses.PostalCode
    FROM
        book_providers
    INNER JOIN contacts ON book_providers.ContactID = contacts.ContactID
    INNER JOIN addresses ON book_providers.AddressID = addresses.AddressID
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

  
    echo json_encode($data);
}


  public function addBookProvider($json) {

    try{
      $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
      $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
      $stmt->execute();
      if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'Email already exists.']);
        exit;
      }

    $this->pdo->beginTransaction();

    $sql = "INSERT INTO `contacts`(
        `Phone`, 
        `Email`)
    VALUES(
        :phone,
        :email)";

    $stmt = $this->pdo->prepare($sql);
    
    $stmt->bindParam(':phone', $json['Phone'], PDO::PARAM_STR);
    $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);

    $stmt->execute();
    $contactId = $this->pdo->lastInsertId();

    unset($stmt);

    $sql = "INSERT INTO `addresses`(
        `Street`,
        `City`,
        `State`,
        `Country`,
        `PostalCode`
    )
    VALUES(
        :street,
        :city,
        :state,
        :country,
        :postalCode
    )";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':street', $json['Street'], PDO::PARAM_STR);
    $stmt->bindParam(':city', $json['City'], PDO::PARAM_STR);
    $stmt->bindParam(':state', $json['State'], PDO::PARAM_STR);
    $stmt->bindParam(':country', $json['Country'], PDO::PARAM_STR);
    $stmt->bindParam(':postalCode', $json['PostalCode'], PDO::PARAM_STR);

    $stmt->execute();

    $addressId = $this->pdo->lastInsertId();

    unset($stmt);

    $sql = "INSERT INTO `book_providers`(`ProviderName`, `ContactID`, `AddressID`)
    VALUES(?, ?, ?)";

    // $stmt->bindParam(':providerName', $json['providerName'], PDO::PARAM_STR);
    // $stmt->bindParam(':contactId', $contactId, PDO::PARAM_INT);
    // $stmt->bindParam(':addressId', $addressId, PDO::PARAM_INT);

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$json['ProviderName'], $contactId, $addressId]);
    
    // $stmt->execute();
    $userId = intval($json['user_id']);
    $userName = $this->getUsersName($userId);
    $this->pdo->commit();
    
    $this->notification->addNotificationForLibrarians("'{$json['ProviderName']}' has been added", 18);
    $this->logs->addLogs($userId, "$userName Added a Book Provider: '{$json['ProviderName']}'");

    unset($stmt); unset($this->pdo);
    http_response_code(201); 
    echo json_encode(['success' => true, 'message' => 'Book Provider added successfully.']);

    } catch (PDOException $e) {
      $this->pdo->rollBack();
      echo json_encode(['success' => false, 'message' => 'Adding of book provider failed' . $e->getMessage()]);
      exit;
    }
  }

  public function fetchBookProvided($json) {

    $sql = 'SELECT
        books.BookID,
        books.Title,
        books.ISBN,
        GROUP_CONCAT(genres.GenreName) as Genres,
        books.PublicationDate,
        authors.AuthorName,
        books.Description,
        publisher.PublisherName
    FROM
        books
    INNER JOIN authors ON authors.AuthorID = books.AuthorID
    INNER JOIN books_genre ON books_genre.BookId = books.BookID
    INNER JOIN genres ON genres.GenreId = books_genre.GenreId
    INNER JOIN publisher ON publisher.PublisherID = books.PublisherID
    WHERE books.ProviderID = :ProviderID
    GROUP BY books.BookID';
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':ProviderID', $json["ProviderID"], PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as &$row) {
        $row['Genres'] = explode(',', $row['Genres']);
    }

    unset($stmt);
    unset($this->pdo);

    echo json_encode($result);

  }

  public function updateBookProvider ($json) {
    
    try {
      // $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
      // $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
      // $stmt->execute();
      // if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      //   http_response_code(409); // Conflict
      //   echo json_encode(['success' => false, 'message' => 'Email already exists.']);
      //   exit;
      // }

      $this->pdo->beginTransaction();
      
      $sql = "UPDATE
          `contacts`
        SET
          `Phone` = :Phone,
          `Email` = :Email
        WHERE ContactID = :ContactID";

      $stmt = $this->pdo->prepare($sql);
      $stmt->bindParam(':Phone', $json['Phone'], PDO::PARAM_STR);
      $stmt->bindParam(':Email', $json['Email'], PDO::PARAM_STR);
      $stmt->bindParam(':ContactID', $json['ContactID'], PDO::PARAM_INT);
      $stmt->execute();

      unset($stmt);


      $stmt = $this->pdo->prepare("UPDATE
          `addresses`
      SET
          `Street` = :Street,
          `City` =  :City,
          `State` =  :State,
          `Country` = :Country,
          `PostalCode` = :PostalCode 
      WHERE
          AddressID = :AddressID");
      $stmt->bindParam(":Street", $json['Street'], PDO::PARAM_STR);
      $stmt->bindParam(":City", $json['City'], PDO::PARAM_STR);
      $stmt->bindParam(":State", $json['State'], PDO::PARAM_STR);
      $stmt->bindParam(":Country", $json['Country'], PDO::PARAM_STR);
      $stmt->bindParam(":PostalCode", $json['PostalCode'], PDO::PARAM_STR);
      $stmt->bindParam(":AddressID", $json['AddressID'], PDO::PARAM_STR);
      $stmt->execute();

      unset($stmt);
      
      // $stmt = $this->pdo->prepare("SELECT * FROM book_providers WHERE ProviderName = :ProviderName");
      // $stmt->bindParam(':ProviderName', $json['ProviderName'], PDO::PARAM_STR);
      // $stmt->execute();
      // if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      //   http_response_code(409); // Conflict
      //   echo json_encode(['success' => false, 'message' => 'ProviderName already exists.']);
      //   exit;
      // }
      $stmt = $this->pdo->prepare('UPDATE
          `book_providers`
      SET
          `ProviderName` = :ProviderName
      WHERE ProviderID = :ProviderID');
      $stmt->bindParam(':ProviderName', $json['ProviderName'], PDO::PARAM_STR);
      $stmt->bindParam(':ProviderID', $json['ProviderID'], PDO::PARAM_INT);
      $stmt->execute();
      $userId = intval($json['user_id']);
      $userName = $this->getUsersName($userId);

      $this->pdo->commit();
      $this->notification->addNotificationForLibrarians("'{$json['ProviderName']}' has been updated", 17);
      $this->logs->addLogs($userId, "$userName Updated a Book Provider: '{$json['ProviderName']}'");


      unset($stmt); unset($this->pdo);
      http_response_code(201); 
      echo json_encode(['success' => true, 'message' => 'Book Provider updated successfully.']);

      // $this->pdo->rollBack();
    } catch (PDOException $e) {
      $this->pdo->rollBack();
      echo json_encode(['success' => false, 'message' => 'Adding of book provider failed' . $e->getMessage()]);
      exit;
    }

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

$bookProvider = new BookProvider($pdo);

switch($operation) {
  case 'fetchBookProviders':
    $bookProvider->fetchBookProviders();
    break;
  case 'addBookProvider':
    $bookProvider->addBookProvider($json);
    break;
  case 'updateBookProvider':
    $bookProvider->updateBookProvider($json);
    break;
  case 'fetchBookProvided':
    $bookProvider->fetchBookProvided($json);
    break;
  default:
    echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
    break;

}


// formdata

// key: operation, value: addBookProvider

// key: json, 
// value: { "email": "hei@gmail.com", "phone": "01122334455", "street" : "Bull's Eye Street", "city": "Cagayan de Oro", "state": "Misamis Oriental", "country": "Philippines", "postalCode": "9000", "providerName": "Basta Provider" }




// SELECT
// 	books.BookID,
//     books.Title,
//     books.ISBN,
//     books.PublicationDate,
//     authors.AuthorName
// FROM
//     books
// INNER JOIN authors ON authors.AuthorID = books.AuthorID
// WHERE books.ProviderID = 1;