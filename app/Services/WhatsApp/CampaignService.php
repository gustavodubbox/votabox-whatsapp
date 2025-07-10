<?php

namespace App\Services\WhatsApp;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignMessage;
use App\Models\WhatsAppContact;
use App\Models\ContactSegment;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage; 
use App\Jobs\SendCampaignMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Services\VotaBoxService; // <-- 1. Importar o novo serviço

class CampaignService
{
    protected WhatsAppBusinessService $whatsappService;
    protected VotaBoxService $votaBoxService; // <-- 2. Adicionar propriedade

    public function __construct(WhatsAppBusinessService $whatsappService, VotaBoxService $votaBoxService) // <-- 3. Injetar no construtor
    {
        $this->whatsappService = $whatsappService;
        $this->votaBoxService = $votaBoxService; // <-- 4. Atribuir
    }

    /**
     * Create a new campaign.
     */
    public function createCampaign(array $data): Campaign
    {
        DB::beginTransaction();

        try {
            // Remove os filtros votabox dos dados principais da campanha para não salvar no DB
            $votaboxFilters = $data['votabox_filters'] ?? null;
            
            // --- INÍCIO DA CORREÇÃO ---
            // Garante que os campos que são arrays sejam convertidos para JSON string
            // antes de serem passados para o método de criação.
            // if (isset($data['template_parameters']) && is_array($data['template_parameters'])) {
            //     $data['template_parameters'] = json_encode($data['template_parameters']);
            // }
            // if (isset($data['votabox_filters']) && is_array($data['votabox_filters'])) {
            //     $data['votabox_filters'] = json_encode($data['votabox_filters']);
            // }
            // --- FIM DA CORREÇÃO ---

            $campaign = Campaign::create($data);

            // Se filtros da VotaBox foram fornecidos, busca e popula os contatos
            if ($votaboxFilters) {
                $this->populateContactsFromVotaBox($campaign, $votaboxFilters);
            }

            DB::commit();

            Log::info('Campaign created successfully', [
                'campaign_id' => $campaign->id,
                'total_contacts' => $campaign->total_contacts
            ]);

            return $campaign;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create campaign: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ATUALIZADO: Lógica de atualização completa.
     */
    public function updateCampaign(Campaign $campaign, array $data): Campaign
    {
        DB::beginTransaction();
        try {
            $votaboxFilters = $data['votabox_filters'] ?? null;

            // Garante a conversão para JSON antes de salvar os dados principais
            if (isset($data['votabox_filters']) && is_array($data['votabox_filters'])) {
                $data['votabox_filters'] = json_encode($data['votabox_filters']);
            }

            // if (isset($data['template_parameters']) && is_array($data['template_parameters'])) {
            //     $data['template_parameters'] = json_encode($data['template_parameters']);
            // }

            Log::info('[UpdateCampaign] Atualizando campanha.', [
                'campaign_id' => $campaign->id,
                'data' => $data
            ]);

            $campaign->update($data);

            // Se novos filtros foram enviados, ressincroniza os contatos
            if ($votaboxFilters) {
                Log::info('[UpdateCampaign] Novos filtros recebidos. Sincronizando contatos.', ['campaign_id' => $campaign->id]);
                
                // 1. Apaga os contatos antigos da campanha
                CampaignContact::where('campaign_id', $campaign->id)->delete();
                Log::info('[UpdateCampaign] Contatos antigos foram apagados.', ['campaign_id' => $campaign->id]);

                // 2. Popula com os novos contatos
                $this->populateContactsFromVotaBox($campaign, $votaboxFilters);
            }

            DB::commit();
            return $campaign->fresh();

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update campaign: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function populateContactsFromVotaBox(Campaign $campaign, array $votaboxFilters): void
    {
        Log::info('[CampaignService] Buscando contatos na VotaBox...', ['filters' => $votaboxFilters]);
        $votaboxPeople = $this->votaBoxService->searchPeople($votaboxFilters);

        if (isset($votaboxPeople['success']) && $votaboxPeople['success'] === false) {
            throw new Exception('Falha ao buscar contatos na VotaBox: ' . ($votaboxPeople['message'] ?? 'Erro desconhecido.'));
        }

        if (empty($votaboxPeople)) {
            Log::warning('[CampaignService] A busca na VotaBox não retornou contatos.', ['campaign_id' => $campaign->id]);
            $campaign->update(['total_contacts' => 0]);
            return;
        }

        $surveyAnswerTags = [];
        if (!empty($votaboxFilters['surveys'])) {
            foreach ($votaboxFilters['surveys'] as $survey) {
                if (!empty($survey['questions'])) {
                    // Pega o valor da chave 'answer' de cada questão.
                    $answers = array_column($survey['questions'], 'answer');
                    $surveyAnswerTags = array_merge($surveyAnswerTags, $answers);
                }
            }
        }

        $contactCount = 0;
        foreach ($votaboxPeople as $index => $person) {
            // --- INÍCIO DA CORREÇÃO ---
            // Ajustado para o novo formato do array de telefones
            if (empty($person['phones']) || empty($person['phones'][0])) {
                Log::warning('[CampaignService] Pessoa ignorada por não ter telefone.', ['person_index' => $index, 'person_name' => $person['fullname'] ?? 'N/A']);
                continue;
            }
            // --- FIM DA CORREÇÃO ---

            try {
                // Ajustado para pegar o telefone diretamente do array de strings
                $phoneNumber = $this->formatPhoneNumber($person['phones'][0]);

                $existingTags = array_map(fn($tag) => $tag['label'], $person['tags'] ?? []);
                $surveyTags = $this->extractSurveyAnswersAsTags($person, $votaboxFilters['surveys'] ?? []);
                $existingTags = array_map(fn($tag) => $tag['label'], $person['tags'] ?? []);
                $allTags = array_unique(array_merge($existingTags, $surveyAnswerTags));
                

                $contact = WhatsAppContact::updateOrCreate(
                    ['phone_number' => $phoneNumber],
                    [
                        // Ajustado para usar 'fullname' e o novo 'id'
                        'name' => $person['fullname'] ?? $phoneNumber,
                        'custom_fields' => ['votabox_id' => $person['id'] ?? null],
                        'tags' => $allTags
                    ]
                );

                CampaignContact::create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'status' => 'pending',
                ]);

                $contactCount++;

            } catch(Exception $e) {
                Log::error('[CampaignService] Falha ao processar um único contato da VotaBox.', [
                    'person_data' => $person,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $campaign->update(['total_contacts' => $contactCount]);
        Log::info("[CampaignService] Processamento finalizado. {$contactCount} contatos associados à campanha.", ['campaign_id' => $campaign->id]);
    }

    /**
     * NOVA FUNÇÃO: Formata um número de telefone para o padrão E.164 (com 55 no início).
     */
    private function formatPhoneNumber(string $number): string
    {
        // Remove todos os caracteres não numéricos
        $number = preg_replace('/\D/', '', $number);

        // Se o número já começa com 55 e tem 12 ou 13 dígitos (55 + DDD + 8 ou 9 dígitos), está correto.
        if (preg_match('/^55\d{10,11}$/', $number)) {
            return $number;
        }
        
        // Se o número tem 10 ou 11 dígitos (DDD + número), adiciona o 55
        if (preg_match('/^\d{10,11}$/', $number)) {
            return '55' . $number;
        }

        // Retorna o número limpo como fallback, mas loga um aviso
        Log::warning('[PhoneFormat] Número de telefone com formato inesperado.', ['number' => $number]);
        return $number;
    }

    /**
     * NOVA FUNÇÃO: Extrai as respostas da pesquisa de uma pessoa e as formata como tags.
     */
    private function extractSurveyAnswersAsTags(array $person, array $surveysFilter): array
    {
        // Esta função é um placeholder. A API da VotaBox não retorna as respostas
        // de uma pessoa na rota /people/search. Se você precisar disso,
        // precisaria de um endpoint adicional na VotaBox que retorne as respostas
        // de uma pessoa para uma dada pesquisa.
        // Por enquanto, esta função retornará um array vazio.
        return [];
    }

    /**
     * Apply segment filters to campaign.
     */
    public function applyCampaignSegments(Campaign $campaign, array $filters): void
    {
        $query = WhatsAppContact::query()->where('status', 'active');

        // Apply filters
        foreach ($filters as $filter) {
            $this->applyFilter($query, $filter);
        }

        $contacts = $query->get();

        // Create campaign contacts
        foreach ($contacts as $contact) {
            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        $campaign->update(['total_contacts' => $contacts->count()]);
    }

    /**
     * Apply individual filter to query.
     */
    protected function applyFilter($query, array $filter): void
    {
        $field = $filter['field'];
        $operator = $filter['operator'];
        $value = $filter['value'];

        switch ($field) {
            case 'tags':
                if ($operator === 'contains') {
                    $query->whereJsonContains('tags', $value);
                } elseif ($operator === 'not_contains') {
                    $query->whereJsonDoesntContain('tags', $value);
                }
                break;

            case 'last_seen_at':
                if ($operator === 'after') {
                    $query->where('last_seen_at', '>', $value);
                } elseif ($operator === 'before') {
                    $query->where('last_seen_at', '<', $value);
                } elseif ($operator === 'between') {
                    $query->whereBetween('last_seen_at', $value);
                }
                break;

            case 'custom_fields':
                $customField = $filter['custom_field'];
                if ($operator === 'equals') {
                    $query->whereJsonContains("custom_fields->{$customField}", $value);
                } elseif ($operator === 'not_equals') {
                    $query->whereJsonDoesntContain("custom_fields->{$customField}", $value);
                }
                break;

            case 'phone_number':
                if ($operator === 'starts_with') {
                    $query->where('phone_number', 'like', $value . '%');
                } elseif ($operator === 'ends_with') {
                    $query->where('phone_number', 'like', '%' . $value);
                } elseif ($operator === 'contains') {
                    $query->where('phone_number', 'like', '%' . $value . '%');
                }
                break;

            default:
                if ($operator === 'equals') {
                    $query->where($field, $value);
                } elseif ($operator === 'not_equals') {
                    $query->where($field, '!=', $value);
                } elseif ($operator === 'like') {
                    $query->where($field, 'like', '%' . $value . '%');
                }
                break;
        }
    }

    /**
     * Start campaign execution.
     */
    public function startCampaign(Campaign $campaign): void
    {
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            throw new Exception("Campaign cannot be started because its status is '{$campaign->status}'.");
        }

        $campaign->start();

        // Dispatch jobs for sending messages
        $this->dispatchCampaignJobs($campaign);

        Log::info('Campaign started', ['campaign_id' => $campaign->id]);
    }

    /**
     * Dispatch jobs for campaign message sending.
     */
    protected function dispatchCampaignJobs(Campaign $campaign): void
    {
        $pendingContacts = $campaign->campaignContacts()
            ->where('status', 'pending')
            ->get();

        // **** INÍCIO DA CORREÇÃO ****
        // Garante que a taxa de limite seja um número positivo.
        // Se for nulo, 0, ou não definido, usamos um valor padrão seguro (ex: 20).
        $messagesPerMinute = $campaign->rate_limit_per_minute > 0 ? $campaign->rate_limit_per_minute : 20;

        // Agora, esta divisão é sempre segura.
        $delayBetweenMessages = 60 / $messagesPerMinute; // segundos
        // **** FIM DA CORREÇÃO ****

        $delay = 0;

        foreach ($pendingContacts as $campaignContact) {
            SendCampaignMessage::dispatch($campaign, $campaignContact)
                ->delay(now()->addSeconds($delay));

            $delay += $delayBetweenMessages;
        }
    }

    /**
     * Send individual campaign message.
     */
    public function sendCampaignMessage(Campaign $campaign, CampaignContact $campaignContact): array
    {
        try {
            $contact = $campaignContact->contact;
            $this->whatsappService->setAccount($campaign->whatsappAccount);

            // ---> INÍCIO DA CORREÇÃO <---
            // Passo 1: Decodificar os parâmetros do template, que vêm como string do DB.
            $templateParams = $campaign->template_parameters;
            if (is_string($templateParams)) {
                $templateParams = json_decode($templateParams, true) ?: [];
            }
            
            // Passo 2: Buscar os detalhes do template para obter o idioma correto.
            $templateData = $this->whatsappService->getTemplateByName($campaign->template_name);
            if (!$templateData) {
                throw new Exception("Template '{$campaign->template_name}' not found on Meta Business account.");
            }
            $languageCode = $templateData['language'];

            // Passo 3: Personalizar os parâmetros já como um array.
            $personalizedComponents = $this->personalizeParameters($templateParams, $contact);
            // ---> FIM DA CORREÇÃO <---

            $result = $this->whatsappService->sendTemplateMessage(
                $contact->phone_number,
                $campaign->template_name,
                $languageCode,
                $personalizedComponents // Envia a estrutura de componentes correta
            );
            
            if ($result['success']) {
                $messageId = $result['data']['messages'][0]['id'] ?? null;
                $campaignContact->update(['status' => 'sent', 'message_id' => $messageId, 'sent_at' => now()]);
                $this->addMessageToConversationHistory($campaign, $contact, $messageId, $templateParams);
            } else {
                $errorMessage = $result['message'] ?? 'Failed to send message.';
                if (isset($result['data']['error']['message'])) {
                    $errorMessage = $result['data']['error']['message'];
                }
                $campaignContact->update(['status' => 'failed', 'error_message' => $errorMessage]);
            }
            
            $this->checkCampaignCompletion($campaign);
            return $result;

        } catch (Exception $e) {
            $campaignContact->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error('Campaign message job failed', [
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Adiciona uma cópia da mensagem da campanha no histórico da conversa individual.
     * Esta é a correção para o erro de 'message_id' sem valor padrão.
     */
    private function addMessageToConversationHistory(Campaign $campaign, WhatsAppContact $contact, ?string $apiMessageId, array $templateParams): void
    {
        try {
            $conversation = WhatsAppConversation::firstOrCreate(
                ['contact_id' => $contact->id, 'whatsapp_account_id' => $campaign->whatsapp_account_id],
                ['conversation_id' => \Illuminate\Support\Str::uuid(), 'assigned_user_id' => $campaign->user_id]
            );

            // ---> INÍCIO DA CORREÇÃO <---
            // Passa o objeto $contact para que a personalização possa ser feita
            $formattedBody = $this->getFormattedMessageBody($campaign, $templateParams, $contact);
            // ---> FIM DA CORREÇÃO <---

            $mediaUrl = $templateParams['header']['url'] ?? null;
            $mediaData = $mediaUrl ? ['url' => $mediaUrl] : null;

            $conversation->messages()->create([
                'message_id' => \Illuminate\Support\Str::uuid(),
                'whatsapp_message_id' => $apiMessageId,
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'user_id' => $campaign->user_id,
                'direction' => 'outbound',
                'type' => 'template',
                'content' => $formattedBody,
                'media' => $mediaData,
                'status' => 'sent',
                'sent_at' => now(),
                'is_ai_generated' => false,
            ]);

            $conversation->touch('last_message_at');

        } catch (\Exception $e) {
            Log::error('CampaignService: Failed to add message to history.', [
                'error' => $e->getMessage(),
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
            ]);
        }
    }

    /**
     * Verifica se a campanha foi concluída após o processamento de um contato.
     * Esta é parte da solução para o primeiro erro reportado.
     */
    private function checkCampaignCompletion(Campaign $campaign): void
    {
        // Força o recarregamento do estado da campanha do banco de dados para evitar condições de corrida
        $campaign->refresh();
        
        // Conta quantos contatos ainda estão com o status 'pending'
        $pendingCount = $campaign->campaignContacts()->where('status', 'pending')->count();
        
        // Se a campanha estiver rodando e não houver mais contatos pendentes, marca como concluída.
        if ($campaign->isRunning() && $pendingCount === 0) {
            $campaign->complete(); // Usa o método do modelo para atualizar o status e a data de conclusão
            Log::info('Campaign completed', ['campaign_id' => $campaign->id]);
        }
    }
    /**
     * Personalize template parameters for contact.
     */
    protected function personalizeParameters(?array $templateParams, WhatsAppContact $contact): array
    {
        $finalParams = ['body' => []];
        if (empty($templateParams)) {
            return $finalParams;
        }

        if (isset($templateParams['header']['type']) && $templateParams['header']['type'] === 'media' && !empty($templateParams['header']['url'])) {
            $format = strtolower($this->getMediaFormatFromUrl($templateParams['header']['url']));
            $finalParams['header'][] = ['type' => $format, $format => ['link' => $templateParams['header']['url']]];
        }
        
        $bodyParams = collect($templateParams)->except('header')->sortKeys()->all();

        foreach ($bodyParams as $fieldKey) {
            $paramValue = '';
            if (str_starts_with($fieldKey, 'custom.')) {
                $customKey = substr($fieldKey, 7);
                $paramValue = $contact->custom_fields[$customKey] ?? '';
            } else {
                $paramValue = $contact->{$fieldKey} ?? '';
            }
            
            $finalParams['body'][] = ['type' => 'text', 'text' => (string) $paramValue];
        }
        
        return $finalParams;
    }

    // NOVO: Método auxiliar para determinar o tipo de mídia pela extensão
    private function getMediaFormatFromUrl(string $url): string
    {
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png' => 'image',
            'mp4' => 'video',
            'pdf' => 'document',
            default => 'document',
        };
    }
    /**
     * Replace placeholders in parameters.
     */
    protected function replaceParameterPlaceholders(array $parameters, array $replacements): array
    {
        $json = json_encode($parameters);
        
        foreach ($replacements as $placeholder => $value) {
            $json = str_replace($placeholder, $value, $json);
        }

        return json_decode($json, true);
    }

    /**
     * Pause campaign.
     */
    public function pauseCampaign(Campaign $campaign): void
    {
        $campaign->pause();
        Log::info('Campaign paused', ['campaign_id' => $campaign->id]);
    }

    /**
     * Resume campaign.
     */
    public function resumeCampaign(Campaign $campaign): void
    {
        $campaign->resume();
        $this->dispatchCampaignJobs($campaign);
        Log::info('Campaign resumed', ['campaign_id' => $campaign->id]);
    }

    /**
     * Cancel campaign.
     */
    public function cancelCampaign(Campaign $campaign): void
    {
        $campaign->cancel();
        Log::info('Campaign cancelled', ['campaign_id' => $campaign->id]);
    }

    /**
     * Get campaign analytics.
     */
    public function getCampaignAnalytics(Campaign $campaign): array
    {
        // Contagem de status diretamente da tabela de contatos da campanha
        $statusCounts = $campaign->campaignContacts()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalContacts = $campaign->total_contacts;
        $sentCount = $statusCounts->get('sent', 0) + $statusCounts->get('delivered', 0) + $statusCounts->get('read', 0);
        $deliveredCount = $statusCounts->get('delivered', 0) + $statusCounts->get('read', 0);
        $readCount = $statusCounts->get('read', 0);
        $failedCount = $statusCounts->get('failed', 0);

        // Calcula as percentagens com base nos dados em tempo real
        $progressPercentage = $totalContacts > 0 ? round((($sentCount + $failedCount) / $totalContacts) * 100, 2) : 0;
        $successRate = $sentCount > 0 ? round(($deliveredCount / $sentCount) * 100, 2) : 0;
        $readRate = $deliveredCount > 0 ? round(($readCount / $deliveredCount) * 100, 2) : 0;

        return [
            'total_contacts' => $totalContacts,
            'sent_count' => $sentCount,
            'delivered_count' => $deliveredCount,
            'read_count' => $readCount,
            'failed_count' => $failedCount,
            'progress_percentage' => $progressPercentage,
            'success_rate' => $successRate,
            'read_rate' => $readRate,
            'status' => $campaign->status,
            'started_at' => $campaign->started_at,
            'completed_at' => $campaign->completed_at,
        ];
    }

    /**
     * Obtém os dados detalhados do relatório da campanha.
     */
    public function getCampaignReportData(Campaign $campaign): array
    {
        // Obtém as estatísticas gerais calculadas em tempo real.
        $analytics = $this->getCampaignAnalytics($campaign);

        // 1. Prepara os dados para o gráfico de pizza (Doughnut).
        $analytics['chart_status'] = [
            'labels' => ['Entregue', 'Lido', 'Falhou', 'Aguardando Entrega'],
            'data' => [
                $analytics['delivered_count'] - $analytics['read_count'],
                $analytics['read_count'],
                $analytics['failed_count'],
                $analytics['sent_count'] - $analytics['delivered_count'],
            ],
        ];

        // 2. Prepara os dados para o gráfico de linha do tempo.
        $readData = $campaign->campaignContacts()
            ->whereNotNull('read_at')
            ->select(DB::raw('DATE(read_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->pluck('total', 'date');

        $analytics['chart_read_timeline'] = [
            'labels' => $readData->keys(),
            'data' => $readData->values(),
        ];

        // 3. Adiciona a lista paginada de contatos da campanha.
        $analytics['contacts'] = $campaign->campaignContacts()
            ->with('contact:id,name,phone_number') // Carrega apenas os campos necessários
            ->orderBy('created_at', 'asc') // Ordena por ordem de envio
            ->paginate(20); // Pagina o resultado

        return $analytics;
    }

    private function getFormattedMessageBody(Campaign $campaign, array $templateParams, WhatsAppContact $contact): ?string
    {
        try {
            $this->whatsappService->setAccount($campaign->whatsappAccount);
            $templateData = $this->whatsappService->getTemplateByName($campaign->template_name);
            
            if (!$templateData) return null;

            $templateBody = null;
            foreach ($templateData['components'] as $component) {
                if ($component['type'] === 'BODY') {
                    $templateBody = $component['text'];
                    break;
                }
            }

            if (!$templateBody) return null;
            
            // --- INÍCIO DA CORREÇÃO FINAL ---
            // Isola apenas os parâmetros do corpo da mensagem
            $bodyParams = $templateParams;
            if (isset($bodyParams['header'])) {
                unset($bodyParams['header']);
            }

            if (!empty($bodyParams)) {
                // Ordena pela chave (1, 2, 3...) para garantir a ordem correta
                ksort($bodyParams);

                foreach ($bodyParams as $placeholderNumber => $field) {
                    // Pula qualquer valor nulo que possa ter sido salvo
                    if ($field === null) continue;

                    // Constrói o placeholder correto, ex: {{1}}
                    $placeholder = '{{' . $placeholderNumber . '}}';
                    
                    // Obtém o valor real do contato
                    $replacement = '';
                    if ($field === 'name') {
                        $replacement = $contact->name ?? '';
                    } elseif ($field === 'phone_number') {
                        $replacement = $contact->phone_number;
                    } elseif (is_string($field) && str_starts_with($field, 'custom.')) {
                        $customKey = substr($field, 7);
                        $replacement = $contact->custom_fields[$customKey] ?? '';
                    } else {
                        $replacement = $field; // Permite valores literais
                    }

                    // Realiza a substituição
                    $templateBody = str_replace($placeholder, (string)$replacement, $templateBody);
                }
            }
            // --- FIM DA CORREÇÃO FINAL ---

            return $templateBody;

        } catch (\Exception $e) {
            Log::error('Could not format message body for logging.', [
                'error' => $e->getMessage(),
                'campaign_id' => $campaign->id
            ]);
            return null;
        }
    }
}

