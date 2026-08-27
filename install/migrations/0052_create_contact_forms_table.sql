CREATE TABLE {prefix}contact_forms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    fields LONGTEXT NOT NULL,
    success_message VARCHAR(255) NOT NULL DEFAULT 'Thanks for your message!',
    redirect_url VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
