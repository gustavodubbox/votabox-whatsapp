<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Exception;

class VotaBoxService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.votabox.base_url');
    }

    /**
     * Cria uma instância do cliente HTTP com os headers de autenticação necessários.
     *
     * @return PendingRequest
     */
    private function buildHttpClient(): PendingRequest
    {
        $token = session('token');
        $tenantId = session('tenant_id');

        if (!$token || !$tenantId) {
            Log::warning('[VotaBoxService] Tentativa de chamada à API sem token ou tenant_id na sessão.');
            throw new Exception('Token de autenticação ou ID do tenant não encontrado na sessão.');
        }

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-ID' => $tenantId,
        ])->baseUrl($this->baseUrl)
          ->timeout(30); // Define um timeout de 30 segundos
    }

    /**
     * Busca a lista de pessoas e telefones.
     *
     * @return array
     */
    public function getPeople(): array
    {
        try {
            $response = $this->buildHttpClient()->get('/people');
            $response->throw(); // Lança exceção para erros 4xx/5xx

            return $response->json();
        } catch (Exception $e) {
            Log::error('[VotaBoxService] Falha ao buscar pessoas.', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Busca a lista de Tags do tipo "Segment".
     *
     * @return array
     */
    public function getTags(): array
    {
        try {
            $response = $this->buildHttpClient()->get('/tags', ['type' => 'Segment']);
            $response->throw();

            return $response->json();
        } catch (Exception $e) {
            Log::error('[VotaBoxService] Falha ao buscar tags.', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Busca a lista de Pesquisas (Surveys).
     *
     * @return array
     */
    public function getSurveys(): array
    {
        try {
            $response = $this->buildHttpClient()->get('/surveys');
            $response->throw();

            return $response->json();
        } catch (Exception $e) {
            Log::error('[VotaBoxService] Falha ao buscar pesquisas.', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Realiza uma busca filtrada de pessoas.
     *
     * @param array $filter O corpo do filtro para o POST request.
     * @return array
     */
    public function searchPeople(array $filter): array
    {
        try {
            // --- INÍCIO DA CORREÇÃO ---
            // Encapsula o filtro dentro de uma chave "filters", como a API espera.
            $payload = ['filters' => $filter];
            // --- FIM DA CORREÇÃO ---

            $response = $this->buildHttpClient()->post('/people/search', $payload);
            $response->throw();

            return $response->json();
        } catch (Exception $e) {
            Log::error('[VotaBoxService] Falha ao pesquisar pessoas.', [
                'error' => $e->getMessage(),
                'filter_sent' => $filter // Mantém o log do filtro original para depuração
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}