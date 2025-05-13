// SQL Database Schema
// ==================
// Save this as database.sql

/*
Database Schema for Flashcard Application

This script creates the necessary tables for the flashcard application:
- users: Stores user account information
- decks: Stores flashcard decks/categories
- cards: Stores the actual flashcards
- progress: Tracks user study progress
- statistics: Aggregates user statistics
*/

CREATE DATABASE IF NOT EXISTS flashcards;
USE flashcards;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- These tables need to be added to database.sql
CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tags (
    tag_id INT AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(50) NOT NULL,
    tag_color VARCHAR(20) DEFAULT '#3E4A89'
);

CREATE TABLE IF NOT EXISTS deck_tags (
    deck_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (deck_id, tag_id),
    FOREIGN KEY (deck_id) REFERENCES decks(deck_id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(tag_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_streaks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    streak_date DATE NOT NULL,
    current_streak INT DEFAULT 1,
    UNIQUE KEY (user_id, streak_date),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Decks table
CREATE TABLE IF NOT EXISTS decks (
    deck_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    deck_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Cards table
CREATE TABLE IF NOT EXISTS cards (
    card_id INT AUTO_INCREMENT PRIMARY KEY,
    deck_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deck_id) REFERENCES decks(deck_id) ON DELETE CASCADE
);

-- Progress table (using Spaced Repetition System)
CREATE TABLE IF NOT EXISTS progress (
    progress_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_id INT NOT NULL,
    ease_factor FLOAT DEFAULT 2.5,  -- For SRS algorithm
    interval INT DEFAULT 0,         -- Days until next review
    repetitions INT DEFAULT 0,      -- Number of successful reviews
    next_review DATE,               -- Next scheduled review date
    last_reviewed TIMESTAMP,        -- Last time this card was reviewed
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(card_id) ON DELETE CASCADE,
    UNIQUE KEY (user_id, card_id)   -- One progress record per user-card pair
);

-- Statistics table
CREATE TABLE IF NOT EXISTS statistics (
    stat_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    deck_id INT NOT NULL,
    date_studied DATE NOT NULL,
    cards_studied INT DEFAULT 0,
    correct_answers INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (deck_id) REFERENCES decks(deck_id) ON DELETE CASCADE,
    UNIQUE KEY (user_id, deck_id, date_studied)  -- One stat record per user-deck-date
);