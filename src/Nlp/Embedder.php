<?php
namespace MHz\MysqlVector\Nlp;

use GuzzleHttp\Client;

class Embedder
{
    const EMBEDDING_DIMENSIONS = 256;
    const MAX_LENGTH = 512;
    const OPENAI_API_URL = 'https://api.openai.com/v1/embeddings';
    private $apiKey;

    public function __construct()
    {
        // Set your OpenAI API key (assumed to be stored in an environment variable)
        $this->apiKey = env('OPENAI_API_KEY');
        
        if (!$this->apiKey) {
            throw new \Exception("OpenAI API key is missing. Please set it in the environment.");
        }
    }

    /**
     * Calculates the embedding of a text using OpenAI Embeddings API.
     * @param array $text Batch of text to embed
     * @return array Batch of embeddings
     * @throws \Exception
     */
    public function embed(array $text): array
    {
        $client = new Client();

        // Prepare the request body
        $body = [
            'model' => 'text-embedding-3-small', // OpenAI embedding model
            'input' => $text,
            'dimensions' => self::EMBEDDING_DIMENSIONS,
        ];

        // Make the API request
        $response = $client->post(self::OPENAI_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body
        ]);

        // Parse the response
        $responseBody = json_decode($response->getBody(), true);

        if (isset($responseBody['error'])) {
            throw new \Exception('OpenAI API error: ' . $responseBody['error']['message']);
        }

        // Extract embeddings
        $embeddings = array_map(function($result) {
            return $result['embedding'];
        }, $responseBody['data']);

        return $embeddings;
    }

    /**
     * Calculates the cosine similarity between two vectors.
     * @param array $a
     * @param array $b
     * @return float
     */
    public function getCosineSimilarity(array $a, array $b): float
    {
        return 1.0 - $this->cosine($a, $b);
    }

    private function cosine(array $a, array $b): float
    {
        $dotproduct = $this->dotProduct($a, $b);
        $normA = $this->l2Norm($a);
        $normB = $this->l2Norm($b);
        return $dotproduct / ($normA * $normB);
    }

    private function dotProduct(array $a, array $b): float
    {
        return \array_sum(\array_map(
            function ($a, $b) {
                return $a * $b;
            },
            $a,
            $b
        ));
    }

    private function l2Norm(array $a): float
    {
        return \sqrt(\array_sum(\array_map(function ($x) {
            return $x * $x;
        }, $a)));
    }
}
