<?php

namespace MHz\MysqlVector;


use KMeans\Space;
use Illuminate\Support\Facades\Log;

class VectorTable
{
    private string $name;
    private int $dimension;
    private string $engine;
    private array $centroidCache;
    private \mysqli $mysqli;

    const SQL_COSIM_FUNCTION = "
CREATE FUNCTION COSIM(v1 JSON, v2 JSON) RETURNS FLOAT DETERMINISTIC BEGIN DECLARE sim FLOAT DEFAULT 0; DECLARE i INT DEFAULT 0; DECLARE len INT DEFAULT JSON_LENGTH(v1); IF JSON_LENGTH(v1) != JSON_LENGTH(v2) THEN RETURN NULL; END IF; WHILE i < len DO SET sim = sim + (JSON_EXTRACT(v1, CONCAT('$[', i, ']')) * JSON_EXTRACT(v2, CONCAT('$[', i, ']'))); SET i = i + 1; END WHILE; RETURN sim; END";
    private int $quantizationSampleSize;

    /**
     * Instantiate a new VectorTable object.
     * @param \mysqli $mysqli The mysqli connection
     * @param string $name Name of the table.
     * @param int $dimension Dimension of the vectors.
     * @param int $quantizationSampleSize Number of vectors to use for quantization.
     * @param string $engine The storage engine to use for the tables
     */
    public function __construct(\mysqli $mysqli, string $name, int $dimension = 384, string $engine = 'InnoDB')
    {
        $this->mysqli = $mysqli;
        $this->name = $name;
        $this->dimension = $dimension;
        $this->engine = $engine;
        $this->centroidCache = [];
    }

    public function getVectorTableName(): string
    {
        return sprintf('%s_vectors', $this->name);
    }

    protected function getCreateStatements(bool $ifNotExists = true): array {
        // Update the binary code length to a CHAR field, with 384 characters (assuming 384 bits in the vector)
        $binaryCodeLengthInChars = $this->dimension * 2; // Each byte is 2 hex chars
    
        $vectorsQuery =
            "CREATE TABLE %s %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vector JSON,
                normalized_vector JSON,
                magnitude DOUBLE,
                binary_code BLOB,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=%s;";
        $vectorsQuery = sprintf($vectorsQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getVectorTableName(), $this->engine);
    
        return [$vectorsQuery];
    }
    

    /**
     * Convert an n-dimensional vector in to an n-bit binary code
     * @param array $vector
     * @return int
     */
    public function vectorToHex(array $vector): string {
        $binary = '';
        foreach($vector as $value) {
            $binary .= $value > 0 ? '1' : '0';
        }

        $padded = str_pad($binary, ceil(strlen($binary) / 8) * 8, '0', STR_PAD_LEFT);

        return $this->binaryToHexadecimal($padded);
    }

    private function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        // Ensure both vectors are the same length
        if (count($vectorA) !== count($vectorB)) {
            return 0;
        }
    
        $dotProduct = 0;
        $magnitudeA = 0;
        $magnitudeB = 0;
    
        // Calculate dot product and magnitudes of both vectors
        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $magnitudeA += $vectorA[$i] * $vectorA[$i];
            $magnitudeB += $vectorB[$i] * $vectorB[$i];
        }
    
        // Prevent division by zero
        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0;
        }
    
        // Return cosine similarity
        return $dotProduct / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
    

    public function stagedSearch(array $vector, int $stages = 4, int $n = 50): array
{
    // Get the table name for the search
    $tableName = $this->getVectorTableName();

    // Normalize the input vector
    $normalizedVector = $this->normalize($vector);
    Log::info('Normalized input vector successfully.');

    // Array to store bottom half results from each stage
    $allBottomResults = [];

    // Calculate the initial dimension and increment based on the number of stages
    $dimension = count($normalizedVector);
    $initialDimension = intdiv($dimension, $stages);
    $increment = $initialDimension;

    Log::info('Initial dimension and increment calculated', [
        'dimension' => $dimension,
        'stages' => $stages,
        'initial_dimension' => $initialDimension,
        'increment' => $increment
    ]);

    // SQL query to retrieve all vectors initially
    $sql = "
        SELECT id, vector, normalized_vector, magnitude
        FROM $tableName
    ";

    // Prepare the query to get all vectors
    $statement = $this->mysqli->prepare($sql);
    if (!$statement) {
        throw new \Exception($this->mysqli->error);
    }

    // Execute the query
    $statement->execute();

    // Bind the result variables for vector retrieval
    $statement->bind_result($id, $v, $nv, $mag);

    // Array to hold all vectors fetched from the database
    $allVectors = [];

    // Fetch all vectors and store them in the array
    while ($statement->fetch()) {
        $allVectors[] = [
            'id' => $id,
            'vector' => json_decode($v, true),
            'normalized_vector' => json_decode($nv, true),
            'magnitude' => $mag
        ];
    }

    // Close the statement
    $statement->close();

    // Start with the initial number of dimensions
    $currentDimension = $initialDimension;

    // Continue until we’ve used all dimensions
    while ($currentDimension <= $dimension) {
        Log::info("Running search for dimension", ['dimension' => $currentDimension]);
    
        // Slice the input vector to the current number of dimensions
        $partialVector = array_slice($vector, 0, $currentDimension); // Slice first
        $normalizedPartialVector = $this->normalize($partialVector); // Normalize after slicing
        Log::info('Normalized partial vector for the current dimension', ['dimension' => $currentDimension]);
    
        // Array to hold candidates from this stage
        $currentCandidates = [];
    
        // Loop through all vectors fetched from the database
        foreach ($allVectors as $vectorData) {
            // Truncate the stored normalized vector to match the current dimension
            $storedVector = array_slice($vectorData['vector'], 0, $currentDimension);
            
            // Normalize after slicing (if needed)
            $storedNormalizedVector = $this->normalize($storedVector);
    
            // Calculate cosine similarity for the truncated and normalized vectors
            $similarity = $this->cosineSimilarity($normalizedPartialVector, $storedNormalizedVector);
    
            // Store the candidate with similarity score
            $currentCandidates[] = [
                'id' => $vectorData['id'],
                'vector' => $vectorData['vector'],
                'normalized_vector' => $storedNormalizedVector,
                'magnitude' => $vectorData['magnitude'],
                'similarity' => $similarity,
            ];
        }

        // Sort the candidates by similarity within this stage (higher scores first)
        usort($currentCandidates, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Retain only the top half for the next stage, and the bottom half for final results
        $retainCount = max(1, intdiv(count($currentCandidates), 2));
        $topHalf = array_slice($currentCandidates, 0, $retainCount);
        $bottomHalf = array_slice($currentCandidates, $retainCount);

        // Save the bottom half from this stage
        $allBottomResults[] = $bottomHalf;

        // Move the top half to the next stage for further refinement
        $allVectors = $topHalf;

        // Increment the dimension for the next stage
        $currentDimension += $increment;

        // Stop the loop if there are no more candidates to process in the top half
        if (empty($allVectors)) {
            break;
        }
    }

    // Final array to hold all compiled results (only bottom half from each stage)
    $compiledResults = [];

    // Merge the final top half results (the ones that survived all stages)
    foreach ($allVectors as $candidate) {
        $compiledResults[] = $candidate;
    }

    //Compile all bottom results from each stage into a single array
    foreach ($allBottomResults as $bottomSet) {
        foreach ($bottomSet as $candidate) {
            $compiledResults[] = $candidate;
        }
    }



    // Limit the final results to the top N matches
    $limitedResults = array_slice($compiledResults, 0, $n);

    // Log the final number of candidates
    Log::info('Returning final sorted candidates', ['total_candidates' => count($limitedResults)]);

    // Return the limited sorted candidates array
    return array_values($limitedResults);
}






    
    

    private function binaryToHexadecimal(string $binaryString): string {
        $hex = '';
        foreach(str_split($binaryString, 4) as $char) {
            $hex .= strtoupper(dechex(bindec($char)));
        }
        $hex = str_pad($hex, ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT);
        return $hex;
    }

    /**
     * Convert an n-dimensional vector into an n-bit binary code
     * @param array $vector
     * @return string (binary data)
     */
    public function vectorToBinary(array $vector): string {
        $binary = '';
        $bit = 0;
        $char = 0;
        
        foreach ($vector as $value) {
            if ($value > 0) {
                $char |= 1 << $bit;
            }

            $bit++;
            if ($bit === 8) { // Every 8 bits (1 byte), append the char to the binary string
                $binary .= chr($char);
                $bit = 0;
                $char = 0;
            }
        }
        
        // If there are remaining bits that don't fill a complete byte, append them
        if ($bit > 0) {
            $binary .= chr($char);
        }

        return $binary; // Return the binary string
    }


    /**
     * Create the tables required for storing vectors
     * @param bool $ifNotExists Whether to use IF NOT EXISTS in the CREATE TABLE statements
     * @return void
     * @throws \Exception If the tables could not be created
     */
    public function initialize(bool $ifNotExists = true): void
    {
        $this->mysqli->begin_transaction();
        foreach ($this->getCreateStatements($ifNotExists) as $statement) {
            $success = $this->mysqli->query($statement);
            if (!$success) {
                $e = new \Exception($this->mysqli->error);
                $this->mysqli->rollback();
                throw $e;
            }
        }

        // Add COSIM function
        $this->mysqli->query("DROP FUNCTION IF EXISTS COSIM");
        $res = $this->mysqli->query(self::SQL_COSIM_FUNCTION);

        if(!$res) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }

        $binaryCodeLengthInBytes = ceil($this->dimension / 8);
        $this->mysqli->query("CREATE INDEX idx_binary_code ON " . $this->getVectorTableName() . " (binary_code($binaryCodeLengthInBytes))");

        $this->mysqli->commit();
    }

    /**
     * Compute the cosine similarity between two normalized vectors
     * @param array $v1 The first vector
     * @param array $v2 The second vector
     * @return float The cosine similarity between the two vectors [0, 1]
     * @throws \Exception
     */
    public function cosim(array $v1, array $v2): float
    {
        $statement = $this->mysqli->prepare("SELECT COSIM(?, ?)");

        if(!$statement) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }

        $v1 = json_encode($v1);
        $v2 = json_encode($v2);

        $statement->bind_param('ss', $v1, $v2);
        $statement->execute();
        $statement->bind_result($similarity);
        $statement->fetch();
        $statement->close();

        return $similarity;
    }

    /**
     * Insert or update a vector
     * @param array $vector The vector to insert or update
     * @param int|null $id Optional ID of the vector to update
     * @return int The ID of the inserted or updated vector
     * @throws \Exception If the vector could not be inserted or updated
     */
    public function upsert(array $vector, int $id = null): int
    {
        $magnitude = $this->getMagnitude($vector);
        $normalizedVector = $this->normalize($vector, $magnitude);
        $binaryCode = $this->vectorToBinary($normalizedVector);  // Convert to binary string
        $tableName = $this->getVectorTableName();

        $insertQuery = empty($id) ?
            "INSERT INTO $tableName (vector, normalized_vector, magnitude, binary_code) VALUES (?, ?, ?, ?)" :
            "UPDATE $tableName SET vector = ?, normalized_vector = ?, magnitude = ?, binary_code = ? WHERE id = $id";

        $statement = $this->mysqli->prepare($insertQuery);
        if(!$statement) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }

        $vector = json_encode($vector);
        $normalizedVector = json_encode($normalizedVector);

        $statement->bind_param('ssds', $vector, $normalizedVector, $magnitude, $binaryCode);  // Bind binary string directly

        $success = $statement->execute();
        if(!$success) {
            throw new \Exception($statement->error);
        }

        $id = $statement->insert_id;
        $statement->close();

        return $id;
    }



    /**
     * Insert multiple vectors in a single query
     * @param array $vectorArray Array of vectors to insert
     * @return array Array of ids of the inserted vectors
     * @throws \Exception
     */
    public function batchInsert(array $vectorArray): array {
        $tableName = $this->getVectorTableName();

        $statement = $this->getConnection()->prepare("INSERT INTO $tableName (vector, normalized_vector, magnitude, binary_code) VALUES (?, ?, ?, UNHEX(?))");
        if(!$statement) {
            throw new \Exception("Prepare failed: " . $this->getConnection()->error);
        }

        $ids = [];
        $this->getConnection()->begin_transaction();
        try {
            foreach ($vectorArray as $vector) {
                $magnitude = $this->getMagnitude($vector);
                $normalizedVector = $this->normalize($vector, $magnitude);
                $binaryCode = $this->vectorToHex($normalizedVector);
                $vectorJson = json_encode($vector);
                $normalizedVectorJson = json_encode($normalizedVector);

                $statement->bind_param('ssds', $vectorJson, $normalizedVectorJson, $magnitude, $binaryCode);

                if (!$statement->execute()) {
                    throw new \Exception("Execute failed: " . $statement->error);
                }

                $ids[] = $statement->insert_id;
            }

            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollback();
            throw $e;
        } finally {
            $statement->close();
        }

        return $ids;
    }

    /**
     * Select one or more vectors by id
     * @param \mysqli $mysqli The mysqli connection
     * @param array $ids The ids of the vectors to select
     * @return array Array of vectors
     */
    public function select(array $ids): array {
        $tableName = $this->getVectorTableName();

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->mysqli->prepare("SELECT id, vector, normalized_vector, magnitude, binary_code FROM $tableName WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));

        $refs = [];
        foreach ($ids as $key => $id) {
            $refs[$key] = &$ids[$key];
        }

        call_user_func_array([$statement, 'bind_param'], array_merge([$types], $refs));
        $statement->execute();
        $statement->bind_result($vectorId, $vector, $normalizedVector, $magnitude, $binaryCode);

        $result = [];
        while ($statement->fetch()) {
            $result[] = [
                'id' => $vectorId,
                'vector' => json_decode($vector, true),
                'normalized_vector' => json_decode($normalizedVector, true),
                'magnitude' => $magnitude,
                'binary_code' => $binaryCode
            ];
        }

        $statement->close();

        return $result;
    }

    public function selectAll(): array {
        $tableName = $this->getVectorTableName();

        $statement = $this->mysqli->prepare("SELECT id, vector, normalized_vector, magnitude, binary_code FROM $tableName");

        if (!$statement) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }

        $statement->execute();
        $statement->bind_result($vectorId, $vector, $normalizedVector, $magnitude, $binaryCode);

        $result = [];
        while ($statement->fetch()) {
            $result[] = [
                'id' => $vectorId,
                'vector' => json_decode($vector, true),
                'normalized_vector' => json_decode($normalizedVector, true),
                'magnitude' => $magnitude,
                'binary_code' => $binaryCode
            ];
        }

        $statement->close();

        return $result;
    }


    private function dotProduct(array $vectorA, array $vectorB): float {
        $product = 0;

        foreach ($vectorA as $position => $value) {
            if (isset($vectorB[$position])) {
                $product += $value * $vectorB[$position];
            }
        }

        return $product;
    }

    /**
     * Returns the number of vectors stored in the database
     * @return int The number of vectors
     */
    public function count(): int {
        $tableName = $this->getVectorTableName();
        $statement = $this->mysqli->prepare("SELECT COUNT(id) FROM $tableName");
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();
        return $count;
    }

    private function getMagnitude(array $vector): float
    {
        $sum = 0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    /**
     * Finds the vectors that are most similar to the given vector
     * @param array $vector The vector to query for
     * @param int $n The number of results to return
     * @return array Array of results containing the id, similarity, and vector
     * @throws \Exception
     */
    public function searchWithHamming(array $vector, int $n = 10): array {
        $tableName = $this->getVectorTableName();
        $normalizedVector = $this->normalize($vector);
        $binaryCode = $this->vectorToHex($normalizedVector);  // Already in hexadecimal
    
        // Initial search using binary codes (no need for HEX/UNHEX or CONVERT anymore)
        $statement = $this->mysqli->prepare("
            SELECT id, 
                hamming_distance(binary_code, ?) AS hamming_distance 
            FROM $tableName 
            ORDER BY hamming_distance 
            LIMIT ?
        ");
        $statement->bind_param('bi', $binaryCode, $n);  // 'b' is used for binary data binding, 'i' for integer

    
        if(!$statement) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }
    
        $statement->execute();
        $statement->bind_result($vectorId, $hd);
    
        $candidates = [];
        while ($statement->fetch()) {
            $candidates[] = $vectorId;
        }
        $statement->close();

        // Rerank candidates using cosine similarity
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $sql = "
        SELECT id, vector, normalized_vector, magnitude, COSIM(normalized_vector, ?) AS similarity
        FROM %s
        WHERE id IN ($placeholders)
        ORDER BY similarity DESC
        LIMIT $n";
        $sql = sprintf($sql, $tableName);

        $statement = $this->mysqli->prepare($sql);

        if(!$statement) {
            $e = new \Exception($this->mysqli->error);
            $this->mysqli->rollback();
            throw $e;
        }

        $normalizedVector = json_encode($normalizedVector);

        $types = str_repeat('i', count($candidates));
        $statement->bind_param('s' . $types, $normalizedVector, ...$candidates);

        $statement->execute();

        $statement->bind_result($id, $v, $nv, $mag, $sim);

        $results = [];
        while ($statement->fetch()) {
            $results[] = [
                'id' => $id,
                'vector' => json_decode($v, true),
                'normalized_vector' => json_decode($nv, true),
                'magnitude' => $mag,
                'similarity' => $sim
            ];
        }

        $statement->close();

        return $results;
    }

    public function search(array $vector, int $n = 10): array
{
    // Get the table name for the search
    $tableName = $this->getVectorTableName();

    // Normalize the input vector, making it ready for cosine similarity comparisons
    $normalizedVector = $this->normalize($vector);

    // Log that normalization was successful (small, but mighty)
    Log::info('Normalized input vector successfully.');

    // Query to retrieve all vectors from the database
    $sql = "
        SELECT id, vector, normalized_vector, magnitude
        FROM $tableName
    ";

    // Prepare the SQL statement to fetch all vectors
    $statement = $this->mysqli->prepare($sql);
    if (!$statement) {
        throw new \Exception($this->mysqli->error);
    }

    // Execute the SQL query
    $statement->execute();

    // Bind the result variables for vector retrieval
    $statement->bind_result($id, $v, $nv, $mag);

    // Array to hold all vectors and their similarity scores
    $allResults = [];

    // Fetch each vector and calculate the cosine similarity
    while ($statement->fetch()) {
        $storedNormalizedVector = json_decode($nv, true);  // Decode stored normalized vector
        $similarity = $this->cosineSimilarity($normalizedVector, $storedNormalizedVector);  // Calculate cosine similarity

        // Store the result
        $allResults[] = [
            'id' => $id,
            'vector' => json_decode($v, true),  // Decode stored vector
            'normalized_vector' => $storedNormalizedVector,
            'magnitude' => $mag,
            'similarity' => $similarity,
        ];
    }

    // Close the statement
    $statement->close();

    // Sort all results by similarity in descending order
    usort($allResults, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    // Log how many results were found
    Log::info('Total vectors found:', ['count' => count($allResults)]);

    // Limit the final results to the top N
    $limitedResults = array_slice($allResults, 0, $n);

    // Log the final number of candidates returned
    Log::info('Returning top N sorted candidates', ['topN' => count($limitedResults)]);

    // Return the top N results
    return $limitedResults;
}


    

    /**
     * Normalize a vector
     * @param array $vector The vector to normalize
     * @param float|null $magnitude The magnitude of the vector. If not provided, it will be calculated.
     * @param float $epsilon The epsilon value to use for normalization
     * @return array The normalized vector
     */
    private function normalize(array $vector, float $magnitude = null, float $epsilon = 1e-10): array {
        $magnitude = !empty($magnitude) ? $magnitude : $this->getMagnitude($vector);
        if ($magnitude == 0) {
            $magnitude = $epsilon;
        }
        foreach ($vector as $key => $value) {
            $vector[$key] = $value / $magnitude;
        }
        return $vector;
    }

    /**
     * Remove a vector from the database
     * @param int $id The id of the vector to remove
     * @return void
     * @throws \Exception
     */
    public function delete(int $id): void {
        $tableName = $this->getVectorTableName();
        $statement = $this->mysqli->prepare("DELETE FROM $tableName WHERE id = ?");
        $statement->bind_param('i', $id);
        $success = $statement->execute();
        if(!$success) {
            throw new \Exception($statement->error);
        }
        $statement->close();
    }

    public function getConnection(): \mysqli {
        return $this->mysqli;
    }
}