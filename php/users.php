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

      // Validate the provided role_id
      if (!$this->isValidRoleId($json['RoleID'])) {
          http_response_code(400); // Bad Request
          echo json_encode(['success' => false, 'message' => 'Invalid role_id provided.']);
          exit;
      }
  
      try {
          // Check if email already exists in contacts
          $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :email");
          $stmt->bindParam(':email', $json['Email'], PDO::PARAM_STR);
          $stmt->execute();
  
          if ($stmt->fetch(PDO::FETCH_ASSOC)) {
              http_response_code(409); // Conflict
              echo json_encode(['success' => false, 'message' => 'Email already exists.']);
              exit;
          }
          
          $this->pdo->beginTransaction();
          
          // Insert into contacts
          $stmt = $this->pdo->prepare("INSERT INTO `contacts`(`Phone`, `Email`) VALUES(?, ?)");
          $stmt->execute([$json["Phone"], $json["Email"]]);
          $contactId = $this->pdo->lastInsertId();
          // Extract the first 2 letters of the first and last name
          $fname = strtolower(substr($json['Fname'], 0, 2));
          $lname = strtolower(substr($json['Lname'], 0, 2));

          // ? Combine the first 2 and first 2 letters of the first and last name
          $passwordFormat = $fname . $lname;
          // Hash the modified name. PASSWORD_DEFAULT ang gi gamit para sa php rata mag hash
          $passwordHash = password_hash($passwordFormat, PASSWORD_DEFAULT);
  
          // Insert into users with the provided role_id
          $stmt = $this->pdo->prepare("INSERT INTO `users`(
              Fname,
              Mname,
              Lname,
              PasswordHash,
              RoleID,
              GenderID,
              ContactID,
              Status
          )
          VALUES(
              :Fname,
              :Mname,
              :Lname,
              :PasswordHash,
              :RoleID,
              :GenderID,
              :ContactID,
              1
          )");
          $stmt->bindParam(':Fname', $json['Fname'], PDO::PARAM_STR);
          $stmt->bindParam(':Mname', $json['Mname'], PDO::PARAM_STR);
          $stmt->bindParam(':Lname', $json['Lname'], PDO::PARAM_STR);
          $stmt->bindParam(':PasswordHash', $passwordHash, PDO::PARAM_STR);
          $stmt->bindParam(':RoleID', $json['RoleID'], PDO::PARAM_INT);
          $stmt->bindParam(':GenderID', $json['GenderID'], PDO::PARAM_INT);
          $stmt->bindParam(':ContactID', $contactId, PDO::PARAM_INT);
          $stmt->execute();
          $userId = $this->pdo->lastInsertId();
  
          // Commit transaction
          $this->pdo->commit();
  
          // Respond with success
          unset($stmt);
          unset($this->pdo);
  
          http_response_code(201); // Created
          echo json_encode(['success' => true, 'message' => 'User registered successfully.', 'user_id' => $userId]);
      } catch (\PDOException $e) {
          // Rollback transaction on error
          $this->pdo->rollBack();
          http_response_code(500); // Internal Server Error
          echo json_encode(['success' => false, 'message' => 'Registration failed.', 'error' => $e->getMessage()]);
      }
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
              SELECT users.UserID, 
              CONCAT(users.Fname, ' ', 
                  COALESCE(CONCAT(SUBSTRING(users.Mname, 1, 1), '. '), ''), 
                  users.Lname) AS Name, 
                users.PasswordHash, 
                user_roles.RoleName,
                users.Status
              FROM users
              JOIN contacts ON users.ContactID = contacts.ContactID
              JOIN user_roles ON users.RoleID = user_roles.RoleID
              WHERE contacts.Email = :email;
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
            if ($user['Status'] != 0) {
                echo json_encode([
              // 'success' => true,
              // 'message' => 'Login successful.',
              'user' => [
                'user_id' => $user['UserID'],
                'name' => $user['Name'],
                'role' => $user['RoleName'],
                'email' => $json['email']
              ]
            ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            }
        // } catch (\PDOException $e) {
            // http_response_code(500); // Internal Server Error
            // echo json_encod0e(['success' => false, 'message' => 'Login failed.', 'error' => $e->getMessage()]);
        // }
    }
    /**
 * Update user profile.
 *
 * @param array $json Associative array containing 'user_id', and fields to update.
 * @return void Outputs JSON response and exits.
 */
public function updateProfile($json) {
  if (empty($json['UserID'])) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'User ID is required.']);
      exit;
  }

  if (isset($json['Email']) && !filter_var($json['Email'], FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
      exit;
  }

  try {
      $this->pdo->beginTransaction();

      // Check for unique email if provided
      if (isset($json['Email'])) {
          $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE Email = :Email AND ContactID != (SELECT ContactID FROM users WHERE UserID = :UserID)");
          $stmt->execute([':Email' => $json['Email'], ':UserID' => $json['UserID']]);
          if ($stmt->fetch(PDO::FETCH_ASSOC)) {
              http_response_code(409); // Conflict
              echo json_encode(['success' => false, 'message' => 'Email already exists.']);
              exit;
          }
      }

      // Update contacts table if Email or Phone is provided
      if (isset($json['Email']) || isset($json['Phone'])) {
          $stmt = $this->pdo->prepare("UPDATE contacts SET Email = COALESCE(:Email, Email), Phone = COALESCE(:Phone, Phone) WHERE ContactID = (SELECT ContactID FROM users WHERE UserID = :UserID)");
          $stmt->execute([':Email' => $json['Email'] ?? null, ':Phone' => $json['Phone'] ?? null, ':UserID' => $json['UserID']]);
      }

      // Update users table
      $stmt = $this->pdo->prepare("UPDATE users SET Fname = :Fname, Mname = :Mname, Lname = :Lname, RoleID = :RoleID, GenderID = :GenderID WHERE UserID = :UserID");
      $stmt->execute([
          ':Fname' => $json['Fname'], ':Mname' => $json['Mname'], ':Lname' => $json['Lname'],
          ':RoleID' => $json['RoleID'], ':GenderID' => $json['GenderID'], ':UserID' => $json['UserID']
      ]);

      $this->pdo->commit();
      http_response_code(200);
      echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
  } catch (\PDOException $e) {
      $this->pdo->rollBack();
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Profile update failed.', 'error' => $e->getMessage()]);
  }
}

/**
 * Change user password.
 *
 * @param array $json Associative array containing 'user_id', 'current_password', 'new_password'.
 * @return void Outputs JSON response and exits.
 */
public function changePassword($json) {
    if (empty($json['user_id']) || empty($json['current_password']) || empty($json['new_password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID, current password, and new password are required.']);
        exit;
    }

    $userId = (int)$json['user_id'];
    $currentPassword = $json['current_password'];
    $newPassword = $json['new_password'];

    try {
        // Fetch current password hash
        $stmt = $this->pdo->prepare("SELECT PasswordHash FROM users WHERE UserID = :user_id");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        // Verify current password
        if (!password_verify($currentPassword, $user['PasswordHash'])) {
            http_response_code(401); // Unauthorized
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        // Hash new password
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password
        $stmt = $this->pdo->prepare("UPDATE users SET PasswordHash = :new_password WHERE UserID = :user_id");
        $stmt->bindParam(':new_password', $newPasswordHash, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        http_response_code(200); // OK
        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
    } catch (\PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Password change failed.', 'error' => $e->getMessage()]);
    }
}
/**
 * Delete a user.
 *
 * @param array $json Associative array containing 'user_id'.
 * @return void Outputs JSON response and exits.
 */
// public function deleteUser($json) {
//     if (empty($json['UserID'])) {
//         http_response_code(400);
//         echo json_encode(['success' => false, 'message' => 'User ID is required.']);
//         exit;
//     }

//     try {
//         $this->pdo->beginTransaction();

//         // Get ContactID before deleting user
//         $stmt = $this->pdo->prepare("SELECT ContactID FROM users WHERE UserID = :UserID");
//         $stmt->bindParam(':UserID', $json['UserID'], PDO::PARAM_INT);
//         $stmt->execute();
//         $user = $stmt->fetch(PDO::FETCH_ASSOC);

//         if (!$user) {
//             $this->pdo->rollBack();
//             http_response_code(404); // Not Found
//             echo json_encode(['success' => false, 'message' => 'User not found.']);
//             exit;
//         }

//         $contactId = $user['ContactID'];

//         // Delete user
//         $stmt = $this->pdo->prepare("DELETE FROM users WHERE UserID = :UserID");
//         $stmt->bindParam(':UserID', $json['UserID'], PDO::PARAM_INT);
//         $stmt->execute();

//         // Optionally delete contact if no other users are linked
//         $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM users WHERE ContactID = :contact_id");
//         $stmt->bindParam(':contact_id', $contactId, PDO::PARAM_INT);
//         $stmt->execute();
//         $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

//         if ($count == 0) {
//             $stmt = $this->pdo->prepare("DELETE FROM contacts WHERE ContactID = :contact_id");
//             $stmt->bindParam(':contact_id', $contactId, PDO::PARAM_INT);
//             $stmt->execute();
//         }

//         $this->pdo->commit();

//         http_response_code(200); // OK
//         echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
//     } catch (\PDOException $e) {
//         $this->pdo->rollBack();
//         http_response_code(500); // Internal Server Error
//         echo json_encode(['success' => false, 'message' => 'User deletion failed.', 'error' => $e->getMessage()]);
//     }
// }
/**
 * Get user details.
 *
 * @param array $json Associative array containing 'user_id'.
 * @return void Outputs JSON response and exits.
 */
public function getUserDetails($json) {
    if (empty($json['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required.']);
        exit;
    }

    $userId = (int)$json['user_id'];

    try {
        $stmt = $this->pdo->prepare("
            SELECT users.UserID, users.Fname, contacts.Email, contacts.Phone, user_roles.RoleName,
                addresses.*
            FROM users
            JOIN contacts ON users.ContactID = contacts.ContactID
            JOIN user_roles ON users.RoleID = user_roles.RoleID
            JOIN addresses ON users.AddressID = addresses.AddressID

            WHERE users.UserID = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        // Return user details
        http_response_code(200); // OK
        echo json_encode(['success' => true, 'user' => $user]);
    } catch (\PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve user details.', 'error' => $e->getMessage()]);
    }
}
/**
 * List all users. Admin only.
 *
 * @param array $json Associative array containing 'admin_user_id' to verify admin privileges.
 * @return void Outputs JSON response and exits.
 */


  public function listUsers($json) {
    if (empty($json['admin_user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Admin User ID is required.']);
        exit;
    }

    $adminUserId = (int)$json['admin_user_id'];

    try {
        // Verify the role of the requester
        $stmt = $this->pdo->prepare("
            SELECT user_roles.RoleName
            FROM users
            JOIN user_roles ON users.RoleID = user_roles.RoleID
            WHERE users.UserID = :admin_user_id
        ");
        $stmt->bindParam(':admin_user_id', $adminUserId, PDO::PARAM_INT);
        $stmt->execute();
        $requesterRole = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$requesterRole || !in_array(strtolower($requesterRole['RoleName']), ['admin', 'librarian'])) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Access denied. Admin or Librarian privileges required.']);
            exit;
        }

        // Fetch all users
        $stmt = $this->pdo->prepare("
            SELECT
                users.UserID,
                users.Fname,
                users.Mname,
                users.Lname,
                contacts.Email,
                contacts.Phone,
                user_roles.RoleName,
                genders.GenderName,
                users.Status
            FROM
                users
            JOIN contacts ON users.ContactID = contacts.ContactID
            JOIN user_roles ON users.RoleID = user_roles.RoleID
            JOIN genders ON users.GenderID = genders.GenderID
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter out admins if the requester is a librarian
        if (strtolower($requesterRole['RoleName']) == 'librarian') {
            $users = array_filter($users, function ($user) {
                return strtolower($user['RoleName']) != 'admin';
            });
        }

        http_response_code(200); // OK
        echo json_encode(['success' => true, 'users' => array_values($users)]);
    } catch (\PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve users.', 'error' => $e->getMessage()]);
    }
  }

  public function archiveUser ($json) {
    
    $sql = "UPDATE users set Status = 0 WHERE UserID = :UserID";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':UserID', $json['UserID'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;

    unset($stmt);
    unset($this->pdo);
    echo json_encode($result);
  }

  public function restoreUser ($json) {
    
    $sql = "UPDATE users set Status = 1 WHERE UserID = :UserID";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':UserID', $json['UserID'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;

    unset($stmt);
    unset($this->pdo);
    echo json_encode($result);
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
// Existing routing logic
switch ($operation) {
    case 'register':
        $user->register($json);
        break;
    case 'login':
        $user->login($json);
        break;
    case 'update_profile':
        $user->updateProfile($json);
        break;
    case 'change_password':
        $user->changePassword($json);
        break;
    // case 'delete_user':
    //     $user->deleteUser($json);
    //     break;
    case 'get_user_details':
        $user->getUserDetails($json);
        break;
    case 'list_users':
        $user->listUsers($json);
        break;
    case 'archive_user':
        $user->archiveUser($json);
        break;
    case 'restore_user':
        $user->restoreUser($json);
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}






// {
//     "operation": "register",
//     "json": { "name": "admin", "email": "admin@admin.com", "phone": "1234567890", "password": "admin", "role_id": "1" }
//     "json": { "name": "librarian", "email": "librarian@librarian.com", "phone": "1234567890", "password": "librarian", "role_id": "2" }
//     "json": { "name": "user", "email": "user@user.com", "phone": "1234567890", "password": "user", "role_id": "3" }






    // "json": {
    //     "name": "user",
    //     "email": "user@user.com",
    //     "phone": "1234567890",
    //     "password": "user",
    //     "role_id": "3"
    // }
// }