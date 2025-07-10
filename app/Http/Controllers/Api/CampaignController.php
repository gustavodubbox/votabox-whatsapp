<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignMessage; 
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact; 
use App\Services\WhatsApp\CampaignService;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage; 
use Illuminate\Support\Arr;

class CampaignController extends Controller
{
    protected CampaignService $campaignService;
    protected WhatsAppBusinessService $whatsappService; // Adicione a propriedade

    // Injete o WhatsAppBusinessService no construtor
    public function __construct(CampaignService $campaignService, WhatsAppBusinessService $whatsappService)
    {
        $this->campaignService = $campaignService;
        $this->whatsappService = $whatsappService;
    }
    

    /**
     * Display a listing of campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::with(['user', 'whatsappAccount'])
            ->where('user_id', auth()->id());

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Sort by created_at desc by default
        $query->orderBy('created_at', 'desc');

        $campaigns = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'campaigns' => $campaigns,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $requestData = $request->all();

        // --- INÍCIO DA CORREÇÃO ---
        // Decodifica todos os campos que são enviados como strings JSON pelo formulário.
        if ($request->has('template_parameters') && is_string($request->template_parameters)) {
            $requestData['template_parameters'] = json_decode($request->template_parameters, true);
        }
        // Adiciona a decodificação para o novo campo votabox_filters
        if ($request->has('votabox_filters') && is_string($request->votabox_filters)) {
            $requestData['votabox_filters'] = json_decode($request->votabox_filters, true);
        }
        // --- FIM DA CORREÇÃO ---

        $validator = Validator::make($requestData, [
            'name' => 'required|string|max:255',
            'type' => 'required|in:immediate,scheduled,recurring',
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'template_name' => 'required|string',
            'template_parameters' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
            'header_media' => 'nullable|file|mimes:jpg,jpeg,png,mp4,pdf|max:16384',
            'votabox_filters' => 'required|array',
            'votabox_filters.tag_ids' => 'present|array',
            'votabox_filters.surveys' => 'present|array',
            'votabox_filters.surveys.*.survey_id' => 'required|string',
            'votabox_filters.surveys.*.questions' => 'required|array|min:1',
            'votabox_filters.surveys.*.questions.*.guid' => 'required|string',
            'votabox_filters.surveys.*.questions.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $campaignData = $validator->validated();
            $campaignData['user_id'] = auth()->id();
            $campaignData['status'] = isset($campaignData['scheduled_at']) ? 'scheduled' : 'draft';

            if ($request->hasFile('header_media')) {
                $path = $request->file('header_media')->storePublicly('campaign_headers', 's3');
                // Garante que a chave 'header' exista
                if (!isset($campaignData['template_parameters']['header'])) {
                    $campaignData['template_parameters']['header'] = [];
                }
                $campaignData['template_parameters']['header']['type'] = 'media';
                $campaignData['template_parameters']['header']['url'] = Storage::disk('s3')->url($path);
            }

            $campaign = $this->campaignService->createCampaign($campaignData);
            
            if ($campaign->type === 'immediate' && $campaign->total_contacts > 0) {
                $this->campaignService->startCampaign($campaign);
            }

            return response()->json(['success' => true, 'message' => 'Campanha criada com sucesso.', 'campaign' => $campaign->load(['user', 'whatsappAccount'])], 201);
        } catch (\Exception $e) {
            \Log::error('Erro ao criar campanha: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Erro interno ao criar campanha: ' . $e->getMessage()], 500);
        }
    }
    public function show(Campaign $campaign): JsonResponse
    {
        if ($campaign->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }
        $campaign->load(['user', 'whatsappAccount']);
        $analytics = $this->campaignService->getCampaignAnalytics($campaign);
        return response()->json(['success' => true, 'campaign' => $campaign, 'analytics' => $analytics]);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $requestData = $request->all();

        // Decodifica campos JSON que vêm como string
        if ($request->has('votabox_filters') && is_string($request->votabox_filters)) {
            $requestData['votabox_filters'] = json_decode($request->votabox_filters, true);
        }

        if ($request->has('template_parameters') && is_string($request->template_parameters)) {
            $requestData['template_parameters'] = json_decode($request->template_parameters, true);
        }

        // Validação (as regras que você já tem)
        $validator = Validator::make($requestData, [
            'name' => 'required|string|max:255',
            'type' => 'required|in:immediate,scheduled,recurring',
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'template_name' => 'required|string',
            'template_parameters' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
            'header_media' => 'nullable|file|mimes:jpg,jpeg,png,mp4,pdf|max:16384',
            'votabox_filters' => 'required|array',
            'votabox_filters.tag_ids' => 'present|array',
            'votabox_filters.surveys' => 'present|array',
            'votabox_filters.surveys.*.survey_id' => 'required|string',
            'votabox_filters.surveys.*.questions' => 'required|array|min:1',
            'votabox_filters.surveys.*.questions.*.guid' => 'required|string',
            'votabox_filters.surveys.*.questions.*.answer' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $campaignData = $validator->validated();

            if ($request->hasFile('header_media')) {
                $path = $request->file('header_media')->storePublicly('campaign_headers', 's3');
                // Garante que a chave 'header' exista
                if (!isset($campaignData['template_parameters']['header'])) {
                    $campaignData['template_parameters']['header'] = [];
                }
                $campaignData['template_parameters']['header']['type'] = 'media';
                $campaignData['template_parameters']['header']['url'] = Storage::disk('s3')->url($path);
            }

            
            // A mágica acontece aqui dentro do serviço agora
            $updatedCampaign = $this->campaignService->updateCampaign($campaign, $campaignData);

            return response()->json(['success' => true, 'message' => 'Campanha atualizada com sucesso.', 'campaign' => $updatedCampaign]);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar campanha: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar campanha.'], 500);
        }
    }

    /**
     * Start a campaign.
     */
    public function start(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        try {
            $this->campaignService->startCampaign($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campanha iniciada com sucesso.',
                'campaign' => $campaign->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao iniciar campanha: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pause a campaign.
     */
    public function pause(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if (!$campaign->isRunning()) {
            return response()->json([
                'success' => false,
                'message' => 'Campanha não está em execução.',
            ], 422);
        }

        $this->campaignService->pauseCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha pausada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Resume a campaign.
     */
    public function resume(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if ($campaign->status !== 'paused') {
            return response()->json([
                'success' => false,
                'message' => 'Campanha não está pausada.',
            ], 422);
        }

        $this->campaignService->resumeCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha retomada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Cancel a campaign.
     */
    public function cancel(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if ($campaign->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Campanha já foi concluída.',
            ], 422);
        }

        $this->campaignService->cancelCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha cancelada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Get campaign analytics.
     */
    public function analytics(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        $analytics = $this->campaignService->getCampaignAnalytics($campaign);

        return response()->json([
            'success' => true,
            'analytics' => $analytics,
        ]);
    }

    /**
     * Remove the specified campaign.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        // Only allow deletion of draft or completed campaigns
        if (!in_array($campaign->status, ['draft', 'completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas campanhas em rascunho, concluídas ou canceladas podem ser excluídas.',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campanha excluída com sucesso.',
        ]);
    }

    /**
     * Get available accounts for campaigns.
     */
    public function accounts(): JsonResponse
    {
        // Aqui você pode adicionar lógica para verificar as contas do usuário logado
        $accounts = WhatsAppAccount::where('status', 'active')->get();
        return response()->json(['success' => true, 'accounts' => $accounts]);
    }


    /**
     * Get available templates for campaigns from Meta API.
     */
    public function templates(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'ID da conta WhatsApp é obrigatório.'], 422);
        }

        try {
            $account = WhatsAppAccount::find($request->whatsapp_account_id);
            $this->whatsappService->setAccount($account);
            $result = $this->whatsappService->getTemplates();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getCampaignContacts(Campaign $campaign): JsonResponse
    {
        if ($campaign->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }

        $contacts = $campaign->contacts()
            ->select('name', 'phone_number')
            ->paginate(200); // Paginação para performance

        return response()->json(['success' => true, 'contacts' => $contacts]);
    }

    public function getReportData(Campaign $campaign): JsonResponse
    {
        // Verifica a propriedade da campanha
        if ($campaign->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }

        // Chama o serviço para obter e processar os dados do relatório
        $reportData = $this->campaignService->getCampaignReportData($campaign);

        return response()->json([
            'success' => true,
            'report' => $reportData,
        ]);
    }

    /**
     * NOVO MÉTODO: Despacha novamente o job para reenviar uma mensagem de campanha.
     */
    public function resend(CampaignContact $campaignContact): JsonResponse
    {
        try {
            // Apenas despacha o mesmo job usado para o envio inicial.
            // Isso garante que toda a lógica de envio seja reutilizada.
            SendCampaignMessage::dispatch($campaignContact->campaign, $campaignContact);

            // Atualiza o status para 'pending' para refletir a nova tentativa
            $campaignContact->update(['status' => 'pending', 'error_message' => null]);

            return response()->json(['success' => true, 'message' => 'Solicitação de reenvio adicionada à fila.']);
        } catch (\Exception $e) {
            \Log::error('Falha ao solicitar o reenvio da mensagem da campanha.', [
                'campaign_contact_id' => $campaignContact->id, 
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => 'Falha ao solicitar o reenvio.'], 500);
        }
    }
}

