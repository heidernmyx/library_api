<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

include '../php/connection/connection.php'; 
require_once 'notification.php'; 
require_once 'logs.php';
class Book {
    private $pdo;
    private $notification;
    private $logs;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->notification = new Notification($pdo); // Initialize Notification class
        $this->logs = new Logs($pdo);
    }

    /**
     * **Add a New Book**
     * Adds a new book to the library.
     *
     * @param array $json
     */
   public function addBook($json) {
    // if (!$this->validateBookInput($json)) {
    //     http_response_code(400); // Bad Request
    //     echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    //     exit;
    // }

    try {
        $this->pdo->beginTransaction();

        // Check if author exists, if not, insert a new author
        $authorId = $this->getOrCreateAuthor($json['author']);

        // Insert the new book
        $stmt = $this->pdo->prepare("
            INSERT INTO books (Title, AuthorID, ISBN, PublicationDate, ProviderID, PublisherID, Description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $json['title'], 
            $authorId, 
            $json['isbn'], 
            $json['publication_date'], 
            $json['provider_id'],
            $json['publisher_id'],
            $json['description']
        ]);

        $bookId = $this->pdo->lastInsertId();

        // Handle genres
        $this->handleGenres($json['genres'], $bookId);

        // Add book copies
        if (isset($json['copies']) && is_numeric($json['copies'])) {
            $this->addBookCopies($bookId, intval($json['copies']));
        }

        $this->pdo->commit();
        $userId = intval($json['user_id']); 

        //**Add ta og logs */
        $userName = $this->getUsersName($json['user_id']);
        $context = "$userName Added a new book '{$json['title']}' to the library.";
        $this->logs->addLogs($json['user_id'], $context);

        // **Add notification for librarians about new book**
        $message = "A new book '{$json['title']}' has been added to the library.";
        $notificationTypeId = 9; // 9 = 'New Book Added'
        $this->notification->addNotificationForLibrarians($message, $notificationTypeId);
        $this->notification->addNotification(null, "A new book '{$json['title']}' has been added to the library.", 9);

        // echo json_encode(['success' => true, 'message' => 'Book added successfully.', 'book_id' => $bookId]);
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to add book.', 'error' => $e->getMessage()]);
    }
}


    /**
     * **Add Book Copies**
     * Adds the specified number of copies for a book.
     *
     * @param int $bookId
     * @param int $copies
     */
  private function addBookCopies($bookId, $copies) {
    for ($i = 1; $i <= $copies; $i++) {
        $stmt = $this->pdo->prepare("
            INSERT INTO book_copies (BookID, CopyNumber, IsAvailable)
            VALUES (?, ?, 1)
        ");
        $stmt->execute([$bookId, $i]);
        // $this->notification->addNotificationForLibrarians("A new copy of Book ID: $bookId has been added.", 17);
    }

    // Only output the success message once, after all copies have been added.
    echo json_encode(['success' => true, 'message' => 'Book added successfully.']);
}

    /**
     * **Validate Book Input**
     * Validates the input data for adding/updating a book.
     *
     * @param array $json
     * @return bool
     */
    private function validateBookInput($json) {
        return !empty($json['title']) && 
               !empty($json['author']) &&
               isset($json['genres']) && is_array($json['genres']) &&
               !empty($json['isbn']) && 
               !empty($json['publication_date']) &&
               !empty($json['provider_id'])&&
               !empty($json['user_id']);
    }
/**
 * **Update Book Copies**
 * Updates the number of copies for a book.
 *
 * @param int $bookId
 * @param int $newCopies
 */
private function updateBookCopies($bookId, $newCopies) {
    // Fetch the current number of copies for this book
    $stmt = $this->pdo->prepare("
        SELECT COUNT(*) as totalCopies FROM book_copies WHERE BookID = ?
    ");
    $stmt->execute([$bookId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentCopies = $result['totalCopies'];

    // If the new number of copies is greater than the current number, add more copies
    if ($newCopies > $currentCopies) {
        $copiesToAdd = $newCopies - $currentCopies;
        for ($i = 1; $i <= $copiesToAdd; $i++) {
            $stmt = $this->pdo->prepare("
                INSERT INTO book_copies (BookID, CopyNumber, IsAvailable)
                VALUES (?, ?, 1)
            ");
            // Increment the CopyNumber accordingly
            $stmt->execute([$bookId, $currentCopies + $i]);
            $this->notification->addNotificationForLibrarians("A new copy of Book ID: $bookId has been added.", 17);
        }
    } 
    // If the new number of copies is less than the current number, mark excess copies as unavailable
    elseif ($newCopies < $currentCopies) {
        $copiesToRemove = $currentCopies - $newCopies;
        // Soft delete or mark copies as unavailable, starting from the highest copy number
        $stmt = $this->pdo->prepare("
            UPDATE book_copies 
            SET IsAvailable = 0
            WHERE BookID = ? 
            ORDER BY CopyNumber DESC
            LIMIT ?
        ");
        $stmt->execute([$bookId, $copiesToRemove]);
        $this->notification->addNotificationForLibrarians("Copies of Book ID: $bookId have been marked as unavailable.", 17);
    }

    // Only output the success message once after updating all copies
    return true;
}



/**
 * **Get or Create Author**
 * Retrieves the AuthorID if the author exists, or creates a new author.
 *
 * @param string $authorName
 * @return int|false
 */
private function getOrCreateAuthor($authorName) {
    // Check if the author exists
    $stmt = $this->pdo->prepare("SELECT AuthorID FROM authors WHERE AuthorName = :name");
    $stmt->bindParam(':name', $authorName, PDO::PARAM_STR);
    $stmt->execute();
    $author = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($author) {
        // If the author exists, return the AuthorID
        return $author['AuthorID'];
    } else {
        // Insert a new author
        $stmt = $this->pdo->prepare("INSERT INTO authors (AuthorName) VALUES (?)");
        $stmt->execute([$authorName]);

        // Get the newly inserted AuthorID
        $newAuthorId = $this->pdo->lastInsertId();

        // Check if the insert was successful (shouldn't return 0 or false)
        if ($newAuthorId && $newAuthorId > 0) {
            // Notify librarians about new author
            $this->notification->addNotificationForLibrarians("A new author '$authorName' has been added.", 18);
            return $newAuthorId;
        } else {
            // Handle the case where the insert failed
            echo "Failed to create new author."; // Debugging
            return false; // Indicate failure to create author
        }
    }
}



    /**
     * **Handle Genres**
     * Manages the association between books and genres.
     *
     * @param array $genres
     * @param int $bookId
     */
    private function handleGenres($genres, $bookId) {
        // Remove all existing genre associations for this book
        $stmt = $this->pdo->prepare("DELETE FROM books_genre WHERE BookId = ?");
        $stmt->execute([$bookId]);

        foreach ($genres as $genre) {
            // Check if genre exists
            $stmt = $this->pdo->prepare("SELECT GenreId FROM genres WHERE GenreName = :genre");
            $stmt->bindParam(':genre', $genre, PDO::PARAM_STR);
            $stmt->execute();
            $genreData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($genreData) {
                $genreId = $genreData['GenreId'];
            } else {
                // Insert a new genre
                $stmt = $this->pdo->prepare("INSERT INTO genres (GenreName) VALUES (?)");
                $stmt->execute([$genre]);
                $genreId = $this->pdo->lastInsertId();
            }

            // Create a relationship in books_genre table
            $stmt = $this->pdo->prepare("INSERT INTO books_genre (BookId, GenreId) VALUES (?, ?)");
            $stmt->execute([$bookId, $genreId]);
        }
    }

    /**
     * **Reserve a Book**
     * Allows a user to reserve a book.
     *
     * @param int $userId
     * @param int $bookId
     */
    public function reserveBook($userId, $bookId) {
        try {
            $this->pdo->beginTransaction();

            // Check if the user has already reserved this book
            $stmt = $this->pdo->prepare("
                SELECT * FROM reservations 
                WHERE UserID = ? AND BookID = ? AND StatusID IN (1, 5, 6)
            ");
            $stmt->execute([$userId, $bookId]);
            $existingReservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingReservation) {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'You have already reserved this book.']);
                return;
            }

            // Insert reservation without modifying copy availability
            $stmt = $this->pdo->prepare("
                INSERT INTO reservations (UserID, BookID, ReservationDate, ExpirationDate, StatusID)
                VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 5)
            ");
            $stmt->execute([$userId, $bookId]);

            // **Add notification for the librarian**
            $bookTitle = $this->getBookTitle($bookId);
            $userName = $this->getUsersName($userId);
            $message = "A new reservation has been made for '{$bookTitle}' by $userName. Please review the reservation";
            $notificationTypeId = 1; // 1 = 'Reservation Made'
            $this->notification->addNotificationForLibrarians($message, $notificationTypeId);
            //**add ta og logs */
            $context = "$userName has made a reservation for '{$bookTitle}'.";
            $this->logs->addLogs($userId, $context);
            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Book reserved successfully.']);
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reserve book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Borrow a Book**
     * Facilitates the borrowing of a book based on a reservation.
     *
     * @param int $userId
     * @param int $reservationId
     */
    public function borrowBook($userId, $reservationId) {
        try {
            $this->pdo->beginTransaction();

            // Fetch reservation details
            $stmt = $this->pdo->prepare("
                SELECT BookID FROM reservations 
                WHERE ReservationID = ? AND StatusID = 6
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reservation) {
                $bookId = $reservation['BookID'];

                // Fetch an available copy
                $stmt = $this->pdo->prepare("
                    SELECT CopyID FROM book_copies 
                    WHERE BookID = ? AND IsAvailable = 1 LIMIT 1
                ");
                $stmt->execute([$bookId]);
                $availableCopy = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($availableCopy) {
                    $copyId = $availableCopy['CopyID'];

                    // Update copy availability
                    $stmt = $this->pdo->prepare("
                        UPDATE book_copies SET IsAvailable = 0 WHERE CopyID = ?
                    ");
                    $stmt->execute([$copyId]);

                    // Update reservation status to 'Fulfilled' (StatusID = 2)
                    $stmt = $this->pdo->prepare("
                        UPDATE reservations SET StatusID = 2 WHERE ReservationID = ?
                    ");
                    $stmt->execute([$reservationId]);

                    // Insert into borrowed_books
                    $stmt = $this->pdo->prepare("
                        INSERT INTO borrowed_books (UserID, BookID, CopyID, BorrowDate, DueDate, StatusID, PenaltyFees)
                        VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 4, 0)
                    ");
                    $stmt->execute([$userId, $bookId, $copyId]);


                    $dueDate = date('Y-m-d', strtotime('+14 days'));
                    $bookTitle = $this->getBookTitle($bookId);
                    $message = "You have successfully borrowed '{$bookTitle}'. Due date is $dueDate.";
                    $notificationTypeId = 2; // 2 = 'Book Borrowed'
                    $userName = $this->getUsersName($userId);
                    $this->notification->addNotification($userName, $message, $notificationTypeId);
                    $this->notification->addNotificationForLibrarians("$userName has borrowed '{$bookTitle}'.", 2); // 2 = 'Book Borrowed'
                   //*add logs */
                     $context = "$userName has borrowed '{$bookTitle}'.";
                    $this->logs->addLogs($userId, $context);
                   
                    $this->pdo->commit();

                    echo json_encode(['success' => true, 'message' => 'Book borrowed successfully.']);
                } else {
                    $this->pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No available copies to borrow.']);
                }
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Invalid reservation or already fulfilled.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to borrow book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Get Book Title**
     * Helper function to retrieve the book title by BookID.
     *
     * @param int $bookId
     * @return string
     */
    private function getBookTitle($bookId) {
        $stmt = $this->pdo->prepare("SELECT Title FROM books WHERE BookID = ?");
        $stmt->execute([$bookId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        return $book ? $book['Title'] : 'Unknown Title';
    }
    private function getUsersName($userId){
        $stmt = $this->pdo->prepare("SELECT Fname FROM users WHERE UserID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $user['Fname'] : 'Unknown User';
    }
    /**
     * **Return a Book**
     * Handles the return of a borrowed book.
     *
     * @param int $userId
     * @param int $borrowId
     */
    public function returnBook($userId, $borrowId) {
        try {
            $this->pdo->beginTransaction();

            // Find the borrowed book record
            $stmt = $this->pdo->prepare("
                SELECT * FROM borrowed_books 
                WHERE BorrowID = ? AND UserID = ? AND StatusID = 4
            ");
            $stmt->execute([$borrowId, $userId]);
            $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($borrowedBook) {
                $copyId = $borrowedBook['CopyID'];
                $bookId = $borrowedBook['BookID'];

                // Calculate penalty fees if any
                $returnDate = date('Y-m-d');
                $dueDate = $borrowedBook['DueDate'];
                $penaltyFees = 0;

                if ($returnDate > $dueDate) {
                    $lateDays = ceil((strtotime($returnDate) - strtotime($dueDate)) / (60 * 60 * 24));
                    $penaltyFees = $lateDays * 1; // Assuming $1 per day
                }

                // Update borrowed_books record
                $stmt = $this->pdo->prepare("
                    UPDATE borrowed_books 
                    SET ReturnDate = ?, PenaltyFees = ?, StatusID = 2 
                    WHERE BorrowID = ?
                ");
                $stmt->execute([$returnDate, $penaltyFees, $borrowId]);

                // **Add notification for the librarian**
                $bookTitle = $this->getBookTitle($bookId);
                $userName = $this->getUsersName($userId);
                $message = " $userName has returned '{$bookTitle}'. Please confirm the return.";
                $notificationTypeId = 3; // 3 = 'Book Returned'
                $this->notification->addNotificationForLibrarians($message, $notificationTypeId);
                //**add loogerrss */
                $context = "$userName has returned '{$bookTitle}'.";
                $this->logs->addLogs($userId, $context);
                // **Notify user if penalty fees are applied**
                if ($penaltyFees > 0) {
                    $message = "You have been charged a penalty fee of \$$penaltyFees for the late return of '{$bookTitle}'.";
                    $notificationTypeId = 8; // 8 = 'Penalty Fees Applied'
                    $this->notification->addNotification($userId, $message, $notificationTypeId);
                }

                $this->pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Book returned successfully.', 'penalty_fees' => $penaltyFees]);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No borrowed record found or book already returned.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to return book.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Confirm Return**
     * Confirms the return of a book by updating the copy's availability and notifying the user.
     *
     * @param int $borrowId
     */
    public function confirmReturn($borrowId) {
        try {
            $this->pdo->beginTransaction();

            // Fetch borrowed book details
            $stmt = $this->pdo->prepare("
                SELECT CopyID, UserID, BookID 
                FROM borrowed_books 
                WHERE BorrowID = ? AND StatusID = 2
            ");
            $stmt->execute([$borrowId]);
            $borrowedBook = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($borrowedBook) {
                $copyId = $borrowedBook['CopyID'];
                $userId = $borrowedBook['UserID'];
                $bookId = $borrowedBook['BookID'];

                // Update book copy to be available
                $stmt = $this->pdo->prepare("
                    UPDATE book_copies SET IsAvailable = 1 WHERE CopyID = ?
                ");
                $stmt->execute([$copyId]);

                // Update borrowed_books StatusID to 3 (Confirmed)
                $stmt = $this->pdo->prepare("
                    UPDATE borrowed_books SET StatusID = 3 WHERE BorrowID = ?
                ");
                $stmt->execute([$borrowId]);

                // **Add notification for the user**
                $bookTitle = $this->getBookTitle($bookId);
                $message = "Your return for '{$bookTitle}' has been confirmed. Thank you!";
                $notificationTypeId = 4; // 4 = 'Return Confirmed'
                $this->notification->addNotification($userId, $message, $notificationTypeId);

                $this->pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Return confirmed and book is now available for borrowing.']);
            } else {
                $this->pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Borrowed book not found or already confirmed.']);
            }
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to confirm return.', 'error' => $e->getMessage()]);
        }
    }

    /**
     * **Fetch Returned Books**
     * Retrieves returned books with StatusID indicating 'Returned' (2).
     */
    public function fetchReturnedBooks() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    bb.BorrowID,
                    u.Fname AS UserName,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    bb.BorrowDate,
                    bb.DueDate,
                    bb.ReturnDate,
                    nt.NotificationTypeName AS BorrowStatus,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
                WHERE
                    bb.StatusID = 2 -- 'Returned'
                ORDER BY
                    bb.ReturnDate DESC
            ");
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'returned_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch returned books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Books**
     * Retrieves all books with their details.
     */
    public function fetchBooks() {
        try {
            $stmt = $this->pdo->prepare("
              SELECT
                books.BookID,
                books.Title,
                books.Description,
                authors.AuthorName,
                publisher.PublisherName,
                books.ISBN,
                books.PublicationDate,
                book_providers.ProviderName,
                COUNT(DISTINCT book_copies.CopyID) AS TotalCopies,
                SUM(
                    CASE WHEN book_copies.IsAvailable = 1 THEN 1 ELSE 0
                END
            ) AS AvailableCopies,
            GROUP_CONCAT(
                DISTINCT genres.GenreName SEPARATOR ', '
            ) AS Genres
            FROM
                books
            LEFT JOIN authors ON books.AuthorID = authors.AuthorID
            LEFT JOIN book_providers ON books.ProviderID = book_providers.ProviderID
            LEFT JOIN book_copies ON books.BookID = book_copies.BookID
            LEFT JOIN books_genre ON books.BookID = books_genre.BookID
            LEFT JOIN genres ON books_genre.GenreID = genres.GenreID
            LEFT JOIN publisher ON books.PublisherID = publisher.PublisherID
            GROUP BY
                books.BookID
            ORDER BY
                books.Title ASC;

            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Borrowed Books**
     * Retrieves borrowed books, optionally filtered by user.
     *
     * @param int|null $userId
     */
public function fetchBorrowedBooks($userId = null, $role = null) {
    try {
        // Case when both userId and role are provided (return both borrowed books and notifications)
        if ($userId !== null && $role !== null) {
            // Query for borrowed books
            $sql = "
                SELECT
                    bb.BorrowID,
                    u.Fname,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    bb.BorrowDate,
                    bb.DueDate,
                    bb.ReturnDate,
                    rs.StatusName AS BorrowStatus,
                    nt.NotificationTypeName AS StatusName,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                JOIN reservation_status rs ON bb.StatusID = rs.StatusID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
                WHERE bb.UserID = ?
                ORDER BY bb.BorrowDate DESC
            ";

            // Query for notifications
            $sql2 = "
                SELECT
                    NotificationID,
                    users.Fname,
                    Message,
                    DateSent,
                    notification_type.NotificationTypeName,
                    notifications.Status
                FROM
                    notifications
                JOIN users ON notifications.UserID = users.UserID
                JOIN notification_type on notifications.NotificationTypeID = notification_type.NotificationTypeID
                WHERE
                    users.UserID = ?
            ";

            // Execute the first query for borrowed books
            $stmt1 = $this->pdo->prepare($sql);
            $stmt1->execute([$userId]);
            $borrowedBooks = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // Execute the second query for notifications
            $stmt2 = $this->pdo->prepare($sql2);
            $stmt2->execute([$userId]);
            $notifications = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Check if notifications query returned any results
            if ($notifications === false) {
                // Handle query failure
                $notifications = [];
            }

            // Return both results in a single response
            echo json_encode([
                'success' => true,
                'borrowed_books' => $borrowedBooks,
                'notifications' => $notifications  // Empty if none found
            ]);

        // Case when only userId is provided (return only borrowed books)
        } else if ($userId !== null) {
            // Query for borrowed books only
            $sql = "
                SELECT
                    bb.BorrowID,
                    u.Fname,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    bb.BorrowDate,
                    bb.DueDate,
                    bb.ReturnDate,
                    rs.StatusName AS BorrowStatus,
                    nt.NotificationTypeName AS StatusName,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                JOIN reservation_status rs ON bb.StatusID = rs.StatusID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
                WHERE bb.UserID = ? 
                ORDER BY bb.BorrowDate DESC
            ";

            // Execute the query
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId]);
            $borrowedBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Return only borrowed books
            echo json_encode([
                'success' => true,
                'borrowed_books' => $borrowedBooks
            ]);

        // Case when neither userId nor role is provided (fetch all records)
        } else {
            $sql = "
                SELECT
                    bb.BorrowID,
                    u.Fname,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    bb.BorrowDate,
                    bb.DueDate,
                    bb.ReturnDate,
                    rs.StatusName AS BorrowStatus,
                    nt.NotificationTypeName AS StatusName,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName,
                    bb.PenaltyFees
                FROM
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                JOIN books b ON bb.BookID = b.BookID
                JOIN reservation_status rs ON bb.StatusID = rs.StatusID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN notification_type nt ON bb.StatusID = nt.NotificationTypeID
                ORDER BY bb.BorrowDate DESC
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Return only borrowed books
            echo json_encode(['success' => true, 'borrowed_books' => $data]);
        }
    } catch (\PDOException $e) {
        // Handle errors
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch borrowed books.',
            'error' => $e->getMessage()
        ]);
    }
}




    /**
     * **Fetch Reserved Books**
     * Retrieves reserved books, optionally filtered by user.
     *
     * @param int|null $userId
     */
    public function fetchReservedBooks($userId = null) {
        try {
            // Base query
            $sql = "
                SELECT 
                    r.ReservationID,
                    u.Fname,
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    r.ReservationDate,
                    r.ExpirationDate,
                    nt.NotificationTypeName AS StatusName,
                    rs.StatusName AS ReservationStatus,
                    b.ISBN,
                    b.PublicationDate,
                    bp.ProviderName
                FROM 
                    reservations r
                JOIN users u ON r.UserID = u.UserID
                JOIN reservation_status rs ON r.StatusID = rs.StatusID
                LEFT JOIN 
                    books b ON r.BookID = b.BookID
                LEFT JOIN 
                    authors a ON b.AuthorID = a.AuthorID
                LEFT JOIN 
                    book_providers bp ON b.ProviderID = bp.ProviderID
                LEFT JOIN 
                    notification_type nt ON r.StatusID = nt.NotificationTypeID
                WHERE 
                    r.StatusID != 2 -- Assuming 2 = 'Completed'
            ";

            // Modify query if userId is provided
            if ($userId !== null) {
                $sql .= " AND r.UserID = ? ORDER BY r.ReservationDate DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$userId]);
            } else {
                // If userId is null, fetch all reserved books
                $sql .= " ORDER BY r.ReservationDate DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
            }

            // Fetch data
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Return the result
            echo json_encode(['success' => true, 'reserved_books' => $data]);
        } catch (\PDOException $e) {
            // Handle errors
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch reserved books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Genres**
     * Retrieves all genres.
     */
    public function fetchGenres() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM genres ORDER BY GenreName ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'genres' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch genres.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Reservation Statuses**
     * Retrieves all reservation statuses.
     */
    public function fetchStatus() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM reservation_status ORDER BY StatusID ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'statuses' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch reservation statuses.',
                'error' => $e->getMessage()
            ]);
        }
    }

   /**
 * **Update Book Details**
 * Updates the details of an existing book.
 *
 * @param array $json
 */
public function updateBooks($json) {
    if (!$this->validateUpdateInput($json)) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid input.']);
        return;
    }

    try {
        $this->pdo->beginTransaction();

        // Get or create the author and log the AuthorID
        $authorId = $this->getOrCreateAuthor($json['author']);

        // Update the book details with the correct AuthorID
        $stmt = $this->pdo->prepare("
            UPDATE books 
            SET Title = ?, AuthorID = ?, ISBN = ?, PublicationDate = ?, ProviderID = ?, PublisherID = ?, Description = ?
            WHERE BookID = ?
        ");
        $stmt->execute([
            $json['title'], 
            $authorId,  // Use the fetched or created AuthorID
            $json['isbn'], 
            $json['publication_date'], 
            $json['provider_id'], 
            $json['publisher_id'],
            $json['description'],
            $json['book_id']  // Book ID being updated
        ]);

        // Update genres
        $this->handleGenres($json['genres'], $json['book_id']);

        if (isset($json['copies']) && is_numeric($json['copies'])) {
            $this->updateBookCopies($json['book_id'], intval($json['copies']));
        }
       
        $this->pdo->commit();

        $userId = intval($json['user_id']);
        $userName = $this->getUsersName($userId);
        $this->logs->addLogs($userId, "Book '{$json['title']}' has been updated by $userName.");
        // // Notify librarians about the book update
        $message = "The book '{$json['title']}' has been updated.";
        $this->notification->addNotificationForLibrarians($message, 11); // 11 = 'Book Updated'

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        // Roll back the transaction in case of error
        $this->pdo->rollBack();

        // Check for foreign key constraint violations
        if (strpos($e->getMessage(), '1451') !== false) {
            http_response_code(409); // Conflict
            echo json_encode(['success' => false, 'message' => 'Cannot update the book. There are borrowed copies that must be returned first.']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Failed to update book.', 'error' => $e->getMessage()]);
        }
    }
}

/**
 * **Validate Update Input**
 * Validates the input data for updating a book.
 *
 * @param array $json
 * @return bool
 */
private function validateUpdateInput($json) {
    // Check if book_id is present and numeric
    if (empty($json['book_id']) || !is_numeric($json['book_id'])) {
        return false;
    }

    // Check if title and author are non-empty strings
    if (empty($json['title']) || empty($json['author'])) {
        return false;
    }

    // Check if genres are provided and is an array (ensure it's not an empty array)
    if (!isset($json['genres']) || !is_array($json['genres']) || empty($json['genres'])) {
        return false;
    }

    // Check if ISBN is provided and is a valid ISBN (optional: add more robust validation if needed)
    if (empty($json['isbn']) || !preg_match('/^[\d\-]+$/', $json['isbn'])) {
        return false;
    }

    // Check if description is non-empty
    if (empty($json['description'])) {
        return false;
    }

    // Check if publication_date is provided and is in a valid date format (YYYY-MM-DD)
    if (empty($json['publication_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $json['publication_date'])) {
        return false;
    }

    // Check if provider_id is present and numeric
    if (empty($json['provider_id']) || !is_numeric($json['provider_id'])) {
        return false;
    }

    // All checks passed, return true
    return true;
}

    /**
     * **Fetch All Borrowed Books**
     * Retrieves all borrowed books without filtering by user.
     */
    public function fetchAllBorrowedBooks() {
        $this->fetchBorrowedBooks(); // Fetch without userId
    }

    /**
     * **Update Reservation Status**
     * Updates the status of a reservation and sends appropriate notifications.
     *
     * @param int $reservationId
     * @param int $statusId
     */
    public function updateReservationStatus($reservationId, $statusId, $user_id) {
    $json = json_decode($_POST['json'], true); 
    try {
        $this->pdo->beginTransaction();

        // Fetch reservation details
        $stmt = $this->pdo->prepare("
            SELECT 
            r.UserID, 
            b.Title 
            FROM reservations r
            JOIN books b ON r.BookID = b.BookID
            WHERE r.ReservationID = ?
        ");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reservation) {
            // Update reservation status
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET StatusID = ? 
                WHERE ReservationID = ?
            ");
            $stmt->execute([$statusId, $reservationId]);

            switch ($statusId) {
                case 2: 
                    $message = "Your reservation for '{$reservation['Title']}' has been fulfilled.";
                    $notificationTypeId = 6; // 6 = 'Reservation Fulfilled'
                    $this->notification->addNotification($reservation['UserID'], $message, $notificationTypeId);
                    break;
                case 6:
                    $message = "Your reservation for '{$reservation['Title']}' has been approved.";
                    $notificationTypeId = 1; // 1 = 'Reservation Approved'
                    $this->notification->addNotification($reservation['UserID'], $message, $notificationTypeId);
                    break;
                case 10: 
                    $message = "Your reservation for '{$reservation['Title']}' has been canceled.";
                    $notificationTypeId = 10; // 10 = 'Reservation Canceled'
                    $this->notification->addNotification($reservation['UserID'], $message, $notificationTypeId);
                    break;
                default:
                    break;
            }

            $staff_id = intval($json['user_id']); // Extract staff_id from the JSON
            $staff = $this->getUsersName($staff_id); 
            $this->logs->addLogs($staff_id, "Reservation status for '{$reservation['Title']}' has been updated by $staff.");
            $this->notification->addNotificationForLibrarians("Reservation status for '{$reservation['Title']}' .",19);
            $this->pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Reservation status updated successfully.']);
        } else {
            $this->pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
        }
    } catch (\PDOException $e) {
        $this->pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update reservation status.', 'error' => $e->getMessage()]);
    }
}


    /**
     * **Fetch Notifications**
     * Retrieves notifications for a specific user.
     *
     * @param int $userId
     */
    public function fetchNotifications($userId) {
        $notifications = $this->notification->fetchNotifications($userId);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
    }

    /**
     * **Fetch Unread Notifications Count**
     * Retrieves the count of unread notifications for a user.
     *
     * @param int $userId
     */
    public function fetchUnreadCount($userId) {
        $unreadCount = $this->notification->fetchUnreadCount($userId);
        echo json_encode(['success' => true, 'unreadCount' => $unreadCount]);
    }
/**
 * **Mark Notification as Read**
 * Marks a specific notification as read for the authenticated user.
 *
 * @param int $notificationId
 * @param int $userId
 * @return void
 */
public function markNotificationAsRead($notificationId) {
    $success = $this->notification->markNotificationAsRead($notificationId);
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    } else {
      
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found or already marked as read.']);
    }
}



    /**
     * **Send Overdue Notices**
     * Triggers the sending of overdue notices to users.
     */
    public function sendOverdueNotices() {
        $this->notification->sendOverdueNotices();
        echo json_encode(['success' => true, 'message' => 'Overdue notices sent successfully.']);
    }

    /**
     * **Send Reservation Expiry Reminders**
     * Triggers the sending of reservation expiry reminders to users.
     */
    public function sendReservationExpiryReminders() {
        $this->notification->sendReservationExpiryReminders();
        echo json_encode(['success' => true, 'message' => 'Reservation expiry reminders sent successfully.']);
    }

    /**
     * **Send Due Date Reminders**
     * Triggers the sending of due date reminders to users.
     */
    public function sendDueDateReminders() {
        $this->notification->sendDueDateReminders();
        echo json_encode(['success' => true, 'message' => 'Due date reminders sent successfully.']);
    }

    public function fetchLogs(){
        $logs = $this->logs->fetchLogs();
        echo json_encode(['success' => true, 'logs' => $logs]);
    }
}

// **Handle Incoming Requests**
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
    $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operation = isset($_POST['operation']) ? $_POST['operation'] : '';
    $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
}

$book = new Book($pdo);

switch ($operation) {
    case 'addBook':
        $book->addBook($json);
        break;
    case 'fetchBooks':
        $book->fetchBooks();
        break;
    case 'updateBook':
        $book->updateBooks($json);
        break;
    case 'fetchGenres':
        $book->fetchGenres();
        break;
    case 'fetchReservedBooks':
        if (isset($json['user_id'])) {
            $book->fetchReservedBooks($json['user_id']);
        } else {
            $book->fetchReservedBooks(); // Call with no userId
        }
        break;
    case 'fetchStatus':
        $book->fetchStatus();
        break;
    case 'fetchReturnedBooks':
        $book->fetchReturnedBooks();
        break;
    case 'confirmReturn':
        if (isset($json['borrow_id'])) {
            $book->confirmReturn($json['borrow_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Borrow ID is required.']);
        }
        break;
    case 'reserveBook':
        if (isset($json['user_id']) && isset($json['book_id'])) {
            $book->reserveBook($json['user_id'], $json['book_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Book ID are required.']);
        }
        break;
    case 'borrowBook':
        if (isset($json['user_id']) && isset($json['reservation_id'])) {
            $book->borrowBook($json['user_id'], $json['reservation_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Reservation ID are required.']);
        }
        break;
    case 'returnBook':
        if (isset($json['user_id']) && isset($json['borrow_id'])) {
            $book->returnBook($json['user_id'], $json['borrow_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and Borrow ID are required.']);
        }
        break;
    case 'fetchBorrowedBooks':
    if (isset($json['user_id']) && isset($json['role'])) {
        // Pass both user_id and role to the function
        $book->fetchBorrowedBooks($json['user_id'], $json['role']);
    } else if (isset($json['user_id'])) {
        // Pass only user_id if role is not provided
        $book->fetchBorrowedBooks($json['user_id']);
    } else {
        // Call without userId
        $book->fetchBorrowedBooks();
    }
    break;

    case 'updateReservationStatus':
        if (isset($json['reservation_id']) && isset($json['status_id']) && isset($json['user_id'])) {
            $book->updateReservationStatus($json['reservation_id'], $json['status_id'], $json['user_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Reservation ID and Status ID are required.']);
        }
        break;

        // ** Notif Functions **
   case 'fetchNotifications':
    if (isset($json['user_id'])) {
        
        $book->fetchNotifications($json['user_id']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    }
    break;
    case 'fetchUnreadCount':
        if (isset($json['user_id'])) {
            $book->fetchUnreadCount($json['user_id']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required.']);
        }
        break;
   case 'markNotificationAsRead':
    if ( isset($json['notificationId'])) {
 

        $notificationId = $json['notificationId']; 

        $book->markNotificationAsRead($notificationId);
    } else {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'User ID are required.']);
    }
    break;


    // ** Notification Functions**
    case 'sendOverdueNotices':
        $book->sendOverdueNotices();
        break;
    case 'sendReservationExpiryReminders':
        $book->sendReservationExpiryReminders();
        break;
    case 'sendDueDateReminders':
        $book->sendDueDateReminders();
        break;

    //**Logs Function */
    case 'fetchLogs':
        $book->fetchLogs();
        break;
    default:
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}

?>
