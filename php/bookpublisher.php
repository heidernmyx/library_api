  <?php

  header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');


  include "../php/connection/connection.php";
  class BookPublisher {
    private $pdo;

    public function __construct($pdo) {
      $this->pdo = $pdo;
    }

    public function fetchPublishers() {
      $stmt = $this->pdo->prepare("SELECT * FROM publisher");
      $stmt->execute();
      $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

      echo json_encode($result);
    }

    public function addPublisher($json) {

      try{

        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
        $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
          http_response_code(409); // Conflict
          echo json_encode(['success' => false, 'message' => 'Email already exists.']);
          exit;
        }

        unset($stmt);

        $stmt = $this->pdo->prepare("SELECT * FROM publisher WHERE PublisherName = :publisherName");
        $stmt->bindParam(':publisherName', $json['publisherName'], PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
          http_response_code(409); // Conflict
          echo json_encode(['success' => false, 'message' => 'Publisher already exists.']);
          exit;
        }

        
    
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
    
        $sql = "INSERT INTO `publisher`(`PublisherName`, `ContactID`, `AddressID`)
        VALUES(?, ?, ?)";
    
    
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$json['PublisherName'], $contactId, $addressId]);
        
        $this->pdo->commit();
        
        unset($stmt); 
        unset($this->pdo);
        http_response_code(201); 

        // $result = $stmt->rowCount() > 0 ? 1 : 0;
        
        echo json_encode(['success' => true, 'message' => 'Book Publisher added successfully.']);
        
    
      } catch (PDOException $e) {
        $this->pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Adding of book publisher failed' . $e->getMessage()]);
        exit;
      }
      // $sql = "INSERT INTO publisher (PublisherName) VALUES (:publisherName)";

        // $stmt = $this->pdo->prepare($sql);
        // $stmt->bindParam(':publisherName', $json['publisherName'], PDO::PARAM_STR);
        // $stmt->execute();
        // $result = $stmt->rowCount() > 0 ? 1 : 0;

        // echo json_encode($result);
      
    }

    public function updatePublisher($json) {
    try {
        $this->pdo->beginTransaction();

        // Update contact details
        $stmt = $this->pdo->prepare("
            UPDATE contacts 
            SET Phone = :phone, Email = :email 
            WHERE ContactID = :contactId
        ");
        $stmt->bindParam(':phone', $json['Phone'], PDO::PARAM_STR);
        $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
        $stmt->bindParam(':contactId', $json['ContactID'], PDO::PARAM_INT);
        $stmt->execute();

        // Update address details
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

        // Update publisher name
        $stmt = $this->pdo->prepare("
            UPDATE publisher 
            SET PublisherName = :publisherName 
            WHERE PublisherID = :publisherId
        ");
        $stmt->bindParam(':publisherName', $json['PublisherName'], PDO::PARAM_STR);
        $stmt->bindParam(':publisherId', $json['PublisherID'], PDO::PARAM_INT);
        $stmt->execute();

        $this->pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Publisher updated successfully.']);
    } catch (PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500);
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


  // Key: operation
  // Value: addPublisher


  // Key: json
  // Value: { "Email": "BastaPublisher@gmail.com", "Phone": "01233210", "Street" : "Sa amoa Street", "City": "Cagayan de Oro", "State": "Misamis Oriental", "Country": "Philippines", "PostalCode": "9000", "PublisherName": "Basta Publisher" }