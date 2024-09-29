<?php
// users.php

header('Content-Type: application/json'); 
header('Access-Control-Allow-Origin: *'); 

include '../php/connection/connection.php';

class User {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function register($json) {
        // Extract and sanitize input data
        // $name = isset($data['name']) ? trim($json['name']) : '';
        // $email = isset($data['email']) ? trim($json['email']) : '';
        // $phone = isset($data['phone']) ? trim($json['phone']) : '';
        // $password = isset($data['password']) ? $json['password'] : '';
        // $roleId = isset($data['role_id']) ? (int)$json['role_id'] : null; // Ensure it's an integer
        // Basic validation
        // if (empty($name) || empty($email) || empty($password) || empty($roleId)) {
        //     http_response_code(400); // Bad Request
        //     echo json_encode(['success' => false, 'message' => 'Name, email, password, and role_id are required.']);
        //     exit;
        // }
        // if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        //     http_response_code(400);
        //     echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        //     exit;
        // }
        // Validate the provided role_id
        if (!$this->isValidRoleId($json['role_id'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid role_id provided.']);
            exit;
        }

        // try {
            // Check if email already exists in contacts
            $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
            $stmt->bindParam(':email', $json['email'], PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'Email already exists.']);
                exit;
            }
            
            $this->pdo->beginTransaction();
            // Insert into contacts
            $stmt = $this->pdo->prepare("INSERT INTO `contacts`(`Phone`, `Email`) VALUES(?, ?)");
            $stmt->execute([$json["phone"], $json["email"]]);
            $contactId = $this->pdo->lastInsertId();
            // Hash the password. PASSWORD_DEFAULT ang gi gamit para sa php rata mag hash
            $passwordHash = password_hash($json['password'], PASSWORD_DEFAULT);
            // Insert into users with the provided role_id
            $stmt = $this->pdo->prepare("INSERT INTO users (Name, PasswordHash, RoleID, ContactID) VALUES (?, ?, ?, ?)");
            $stmt->execute([$json['name'], $passwordHash, (int)$json['role_id'], $contactId]);
            $userId = $this->pdo->lastInsertId();
            // Commit transaction
            $this->pdo->commit();
            // Respond with success
            unset($stmt);
            unset($this->pdo);

            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'User registered successfully.', 'user_id' => $userId]);
        // } catch (\PDOException $e) {
        //     // Rollback transaction on error
        //     $this->pdo->rollBack();
        //     http_response_code(500); // Internal Server Error
        //     echo json_encode(['success' => false, 'message' => 'Registration failed.', 'error' => $e->getMessage()]);
        // }
    }

    /**
     * Validate if the provided role_id exists in the user_roles table.
     *
     * @param int $roleId The role ID to validate
     * @return bool True if valid, False otherwise
     */
    private function isValidRoleId($roleId) {
        // try {
            $stmt = $this->pdo->prepare("SELECT * FROM user_roles WHERE RoleID = :role_id");
            $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result;
        // } catch (PDOException $e) {

        //     return false;
        // }
    }

    /**
     * Log in a user.
     *
     * @param array $data Associative array containing 'email' and 'password'
     * @return void Outputs JSON response and exits
     */
    public function login($json) {

        // Basic validation
        // if (empty($email) || empty($password)) {
        //     http_response_code(400); // Bad Request
        //     echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        //     exit;
        // }

        // if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        //     http_response_code(400);
        //     echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        //     exit;
        // }

        // try {
            // Retrieve user information by joining users and contacts tables
            $stmt = $this->pdo->prepare("
              SELECT users.UserID, users.Name, users.PasswordHash, user_roles.RoleName, user_roles.RoleName
              FROM users
              JOIN contacts ON users.ContactID = contacts.ContactID
              JOIN user_roles ON users.RoleID = user_roles.RoleID
              WHERE contacts.Email = :email
            ");
            $stmt->bindParam(':email', $json['email'], PDO::PARAM_STR);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                http_response_code(401); // Unauthorized
                echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
                exit;
            }

            if (!password_verify($json['password'], $user['PasswordHash'])) {
                http_response_code(401); // Unauthorized
                echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
                exit;
            }

            unset($pdo);
            unset($stmt);
            // Return the user data (excluding sensitive information... password)
            echo json_encode([
              // 'success' => true,
              // 'message' => 'Login successful.',
              'user' => [
                'user_id' => $user['UserID'],
                'name' => $user['Name'],
                'role' => $user['RoleName']
              ]
            ]);
        // } catch (\PDOException $e) {
            // http_response_code(500); // Internal Server Error
            // echo json_encod0e(['success' => false, 'message' => 'Login failed.', 'error' => $e->getMessage()]);
        // }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
  $operation = $_GET['operation'];
  $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $operation = $_POST['operation'];
  $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
}

// Instantiate the User class
$user = new User($pdo);
//Diri sa switch gina pasa nato ang raw data from the frontend ($input)
switch ($operation) {
    case 'register':
        $user->register($json);
        break;
    case 'login':
        $user->login($json);
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode("Invalid operation.");
        break;
}
?>