<?php
class Notification {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * **Fetch Notifications**
     * Retrieves all notifications for a specific user.
     *
     * @param int $user_id
     * @return array
     */
    public function fetchNotifications($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM notifications 
                WHERE UserID = ? 
                ORDER BY DateSent DESC
            ");
            $stmt->execute([$user_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Handle exception or log error
            return [];
        }
    }

    /**
     * **Fetch Unread Count**
     * Retrieves the count of unread notifications for a user.
     *
     * @param int $user_id
     * @return int
     */
    public function fetchUnreadCount($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as unreadCount 
                FROM notifications 
                WHERE UserID = ? AND Status = 'Unread'
            ");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return intval($result['unreadCount']);
        } catch (\PDOException $e) {
            // Handle exception or log error
            return 0;
        }
    }

    /**
     * **Mark Notification as Read**
     * Marks a specific notification as read.
     *
     * @param int $notificationId
     * @return bool
     */
    public function markNotificationAsRead($notificationId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE notifications 
                SET Status = 'Read' 
                WHERE NotificationID = ?
            ");
            return $stmt->execute([$notificationId]);
        } catch (\PDOException $e) {
            // Handle exception or log error
            return false;
        }
    }

    /**
     * **Add Notification for Librarians**
     * Adds a notification to all librarians and Admins.
     *
     * @param string $message
     * @param int $notificationTypeId
     */
    public function addNotificationForLibrarians($message, $notificationTypeId) {
        try {
            $stmt = $this->pdo->prepare("
                                        SELECT
                                            UserID,
                                            user_roles.RoleID,
                                            user_roles.RoleName
                                        FROM
                                            `users`
                                        JOIN user_roles ON user_roles.RoleID = users.RoleID
                                        WHERE
                                            user_roles.RoleID IN (1,2) 
            ");
            $stmt->execute();
            $librarians = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtInsert = $this->pdo->prepare("
                INSERT INTO notifications (UserID, Message, DateSent, Status, NotificationTypeID)
                VALUES (?, ?, NOW(), 'Unread', ?)
            ");

            foreach ($librarians as $librarian) {
                $stmtInsert->execute([
                    $librarian['UserID'],
                    $message,
                    $notificationTypeId
                ]);
            }
        } catch (\PDOException $e) {
            // Handle exception or log error
        }
    }

    /**
     * **Add Notification for a User**
     * Adds a notification to a specific user.
     *
     * @param int $userId
     * @param string $message
     * @param int $notificationTypeId
     */
   public function addNotification($userId, $message, $notificationTypeId) {
    try {
        // If $userId is null, send the notification to all users except those with roleID 1 or 2
        if ($userId === null) {
            // Get all users except those with roleID 1 or 2
            $stmt = $this->pdo->prepare("
                SELECT UserID 
                FROM users 
                WHERE RoleID NOT IN (1, 2)
            ");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Loop through each user and send the notification
            foreach ($users as $user) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (UserID, Message, DateSent, Status, NotificationTypeID)
                    VALUES (?, ?, NOW(), 'Unread', ?)
                ");
                $stmt->execute([$user['UserID'], $message, $notificationTypeId]);
            }

        } else {
            // If $userId is provided, send the notification to that specific user
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (UserID, Message, DateSent, Status, NotificationTypeID)
                VALUES (?, ?, NOW(), 'Unread', ?)
            ");
            $stmt->execute([$userId, $message, $notificationTypeId]);
        }
    } catch (\PDOException $e) {
        // Handle exception or log error
    }
}


    /**
     * **Send Overdue Notices**
     * Sends overdue notices to users with overdue books.
     */
    public function sendOverdueNotices() {
        try {
            // Fetch all borrowed books that are overdue
            $stmt = $this->pdo->prepare("
                SELECT bb.BorrowID, bb.UserID, b.Title, bb.DueDate 
                FROM borrowed_books bb
                JOIN books b ON bb.BookID = b.BookID
                WHERE bb.DueDate < CURDATE() AND bb.StatusID = 4
            ");
            $stmt->execute();
            $overdueBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($overdueBooks as $book) {
                $message = "Your borrowed book '{$book['Title']}' was due on {$book['DueDate']}. Please return it as soon as possible to avoid penalties.";
                $notificationTypeId = 5; // Assuming 5 = 'Overdue Notice'
                $this->addNotification($book['UserID'], $message, $notificationTypeId);
            }
        } catch (\PDOException $e) {
            // Handle exception or log error
        }
    }

    /**
     * **Send Reservation Expiry Reminders**
     * Sends reminders to users whose reservations are about to expire.
     */
    public function sendReservationExpiryReminders() {
        try {
            // Fetch all reservations that are about to expire in 2 days
            $stmt = $this->pdo->prepare("
                SELECT r.ReservationID, r.UserID, b.Title, r.ExpirationDate 
                FROM reservations r
                JOIN books b ON r.BookID = b.BookID
                WHERE r.ExpirationDate = DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND r.StatusID = 5
            ");
            $stmt->execute();
            $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reservations as $reservation) {
                $message = "Your reservation for '{$reservation['Title']}' will expire on {$reservation['ExpirationDate']}. Please borrow it before it expires.";
                $notificationTypeId = 6; // Assuming 6 = 'Reservation Expiry Reminder'
                $this->addNotification($reservation['UserID'], $message, $notificationTypeId);
            }
        } catch (\PDOException $e) {
            // Handle exception or log error
        }
    }

    /**
     * **Send Due Date Reminders**
     * Sends reminders to users about upcoming due dates.
     */
    public function sendDueDateReminders() {
        try {
            // Fetch all borrowed books due in 3 days
            $stmt = $this->pdo->prepare("
                SELECT bb.BorrowID, bb.UserID, b.Title, bb.DueDate 
                FROM borrowed_books bb
                JOIN books b ON bb.BookID = b.BookID
                WHERE bb.DueDate = DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND bb.StatusID = 4
            ");
            $stmt->execute();
            $dueReminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dueReminders as $reminder) {
                $message = "Reminder: The book '{$reminder['Title']}' you borrowed is due on {$reminder['DueDate']}. Please return it by the due date or renew it if possible.";
                $notificationTypeId = 7; // Assuming 7 = 'Due Date Reminder'
                $this->addNotification($reminder['UserID'], $message, $notificationTypeId);
            }
        } catch (\PDOException $e) {
            // Handle exception or log error
        }
    }
}


?>
