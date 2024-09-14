Here's a `README.md` file that reflects the current functionality of your `VectorTable` class.

```markdown
# MySQL Vector Table

This repository provides an implementation for storing, searching, and manipulating vectors in a MySQL database using cosine similarity and binary codes for efficient search. The main class `VectorTable` provides a variety of functions to handle vector data, including inserting, updating, searching, and computing similarity between vectors.

## Features

- **Cosine Similarity Search (COSIM)**: Efficiently compute the cosine similarity between vectors stored as JSON in MySQL.
- **Binary Code Representation**: Vectors are stored in binary form for efficient querying and similarity computation.
- **Hamming Distance Search (Optional)**: A method for searching vectors based on Hamming distance is also available, though not the primary method.
- **Vector Normalization**: Automatically normalizes vectors before inserting and computing similarity.
- **Batch Insertion**: Support for inserting multiple vectors at once.
- **Vector Management**: Functions to insert, update, delete, and retrieve vectors.
  
## Table Structure

The vectors are stored in a MySQL table with the following structure:
```sql
CREATE TABLE `your_table_name_vectors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vector` JSON,                  -- The original vector
    `normalized_vector` JSON,        -- The normalized vector
    `magnitude` DOUBLE,              -- The magnitude of the vector
    `binary_code` BLOB,              -- Binary representation of the vector for efficient searching
    `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

## MySQL Function: `COSIM`

The class defines a custom MySQL function `COSIM` to compute cosine similarity between two vectors stored as JSON.

```sql
CREATE FUNCTION COSIM(v1 JSON, v2 JSON) RETURNS FLOAT DETERMINISTIC
BEGIN
    DECLARE sim FLOAT DEFAULT 0;
    DECLARE i INT DEFAULT 0;
    DECLARE len INT DEFAULT JSON_LENGTH(v1);
    
    IF JSON_LENGTH(v1) != JSON_LENGTH(v2) THEN RETURN NULL; END IF;
    
    WHILE i < len DO
        SET sim = sim + (JSON_EXTRACT(v1, CONCAT('$[', i, ']')) * JSON_EXTRACT(v2, CONCAT('$[', i, ']')));
        SET i = i + 1;
    END WHILE;
    
    RETURN sim;
END;
```

## Core Functionalities

### `search()`

Search for vectors in the table that are most similar to a given input vector based on cosine similarity.

#### Usage:
```php
$results = $vectorTable->search($inputVector, $n = 10);
```

- **$inputVector**: The vector to compare against the stored vectors.
- **$n**: The number of results to return (default is 10).

The function returns an array of the most similar vectors sorted by cosine similarity.

### `searchWithHamming()`

Search for vectors in the table based on Hamming distance between binary representations of vectors.

#### Usage:
```php
$results = $vectorTable->searchWithHamming($inputVector, $n = 10);
```

### `upsert()`

Insert or update a vector in the table. If the vector already exists (based on ID), it will be updated. Otherwise, a new vector will be inserted.

#### Usage:
```php
$id = $vectorTable->upsert($vector, $id = null);
```

- **$vector**: The vector to insert or update.
- **$id**: The optional ID of the vector to update. If not provided, a new vector is inserted.

### `batchInsert()`

Insert multiple vectors into the table in a single transaction.

#### Usage:
```php
$ids = $vectorTable->batchInsert($vectorArray);
```

- **$vectorArray**: An array of vectors to be inserted.

### `select()`

Retrieve vectors from the table by their IDs.

#### Usage:
```php
$vectors = $vectorTable->select($ids);
```

- **$ids**: An array of vector IDs to retrieve.

### `selectAll()`

Retrieve all vectors from the table.

#### Usage:
```php
$vectors = $vectorTable->selectAll();
```

### `delete()`

Remove a vector from the table by its ID.

#### Usage:
```php
$vectorTable->delete($id);
```

- **$id**: The ID of the vector to remove.

### `normalize()`

Normalize a vector by its magnitude.

#### Usage:
```php
$normalizedVector = $vectorTable->normalize($vector);
```

- **$vector**: The vector to normalize.

### `cosim()`

Compute the cosine similarity between two vectors.

#### Usage:
```php
$similarity = $vectorTable->cosim($v1, $v2);
```

- **$v1**: The first vector.
- **$v2**: The second vector.

### `count()`

Get the total number of vectors stored in the database.

#### Usage:
```php
$totalVectors = $vectorTable->count();
```

### `vectorToBinary()`

Convert an n-dimensional vector into a binary code.

#### Usage:
```php
$binaryCode = $vectorTable->vectorToBinary($vector);
```

## Initialization

To create the necessary tables and functions, you need to call the `initialize()` method.

```php
$vectorTable->initialize();
```

This will create the vectors table and the cosine similarity function (`COSIM`) in your database.

## Installation

1. Set up a MySQL database.
2. Create an instance of the `VectorTable` class.
3. Call `initialize()` to set up the table and functions.
4. Start inserting, updating, and searching for vectors!

### Example:

```php
$mysqli = new \mysqli('localhost', 'user', 'password', 'database');

$vectorTable = new \MHz\MysqlVector\VectorTable($mysqli, 'example_table', 384);
$vectorTable->initialize();

$vector = [1, 2, 3, 4, 5];
$vectorTable->upsert($vector);

$similarVectors = $vectorTable->search($vector);
```

## License

This project is licensed under the MIT License.
```

This `README.md` outlines the features, core functionalities, usage examples, and setup instructions for your `VectorTable` class. It reflects the current state of your code, including both cosine similarity and Hamming distance-based searches, and explains how to initialize, insert, search, and manage vectors in the MySQL database.