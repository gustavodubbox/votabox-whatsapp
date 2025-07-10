<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">Campanhas</h1>
        <button @click="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center">
          <PlusIcon class="h-5 w-5 mr-2" />
          Nova Campanha
        </button>
      </div>
    </template>

    <div class="mb-6 flex flex-wrap gap-4">
      <input type="text" v-model="filterForm.search" placeholder="Buscar campanhas..." @input="searchCampaigns" class="form-input flex-grow">
      <select v-model="filterForm.status" @change="searchCampaigns" class="form-input w-48">
        <option value="">Todos os status</option>
        <option value="draft">Rascunho</option>
        <option value="scheduled">Agendada</option>
        <option value="running">Executando</option>
        <option value="paused">Pausada</option>
        <option value="completed">Concluída</option>
        <option value="cancelled">Cancelada</option>
      </select>
    </div>

    <div v-if="campaigns.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="campaign in campaigns.data" :key="campaign.id" class="bg-white shadow rounded-lg overflow-hidden hover:shadow-md transition-shadow flex flex-col">
        <div class="p-6 flex-grow">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 truncate">{{ campaign.name }}</h3>
            <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', getStatusClass(campaign.status)]">
               {{ getStatusText(campaign.status) }}
            </span>
          </div>
          <p class="text-sm text-gray-600 mb-4 h-10">{{ campaign.description }}</p>
          <div class="space-y-3">
            <div class="flex justify-between text-sm">
              <span class="text-gray-500">Contatos:</span>
              <span class="font-medium">{{ campaign.total_contacts }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
              <div class="bg-green-600 h-2.5 rounded-full" :style="{ width: getProgress(campaign) + '%' }"></div>
            </div>
          </div>
        </div>
        <div class="px-6 py-3 bg-gray-50 border-t flex justify-end space-x-3">
          <Link
              v-if="campaign.status === 'completed'"
              :href="route('campaigns.report', campaign.id)"
              class="text-sm font-medium text-purple-600 hover:text-purple-700"
            >
              Ver Relatório
            </Link>
          <button @click="performAction('pause', campaign)" v-if="campaign.status === 'running'" class="text-sm font-medium text-yellow-600 hover:text-yellow-700">Pausar</button>
          <button @click="performAction('resume', campaign)" v-if="campaign.status === 'paused'" class="text-sm font-medium text-green-600 hover:text-green-700">Retomar</button>
          <button @click="performAction('start', campaign)" v-if="['draft', 'scheduled'].includes(campaign.status)" class="text-sm font-medium text-green-600 hover:text-green-700">Iniciar Agora</button>
          <button @click="openModal(campaign)" class="text-sm font-medium text-blue-600 hover:text-blue-700" v-if="campaign.status !== 'completed'">Editar</button>
          <button @click="performAction('delete', campaign)" v-if="['draft', 'completed', 'cancelled'].includes(campaign.status)" class="text-sm font-medium text-red-600 hover:text-red-700">Excluir</button>
        </div>
      </div>
    </div>
     <div v-else class="text-center py-12 bg-white shadow rounded-lg">
      <MegaphoneIcon class="h-12 w-12 text-gray-400 mx-auto mb-4" />
      <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhuma campanha encontrada</h3>
      <p class="text-gray-500 mb-4">Crie sua primeira campanha para começar.</p>
    </div>

    <div v-if="campaigns && campaigns.links && campaigns.links.length > 3" class="mt-6 flex justify-center items-center space-x-1">
        <Link
            v-for="(link, index) in campaigns.links"
            :key="index"
            :href="link.url"
            v-html="link.label"
            class="px-4 py-2 text-sm rounded-md"
            :class="{
                'bg-green-600 text-white': link.active,
                'text-gray-700 bg-white hover:bg-gray-50': !link.active && link.url,
                'text-gray-400 cursor-not-allowed': !link.url
            }"
        />
    </div>
    
    <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
      <div class="relative mx-auto p-6 border w-11/12 md:w-3/4 lg:max-w-3xl shadow-lg rounded-md bg-white">
        <form @submit.prevent="saveCampaign" class="space-y-6 max-h-[90vh] overflow-y-auto pr-4">
          <h3 class="text-lg font-medium text-gray-900">{{ form.id ? 'Editar' : 'Nova' }} Campanha</h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="form-label">Nome da Campanha</label><input v-model="form.name" type="text" required class="form-input"/></div>
            <div><label class="form-label">Tipo de Envio</label><select v-model="form.type" required class="form-input"><option value="immediate">Imediato</option><option value="scheduled">Agendado</option></select></div>
            <div v-if="form.type === 'scheduled'"><label class="form-label">Data e Hora do Envio</label><input v-model="form.scheduled_at" type="datetime-local" class="form-input" :required="form.type === 'scheduled'" /></div>
            <div class="md:col-span-2"><label class="form-label">Conta do WhatsApp</label><select v-model="form.whatsapp_account_id" @change="onAccountChange" required class="form-input"><option :value="null" disabled>Selecione uma conta</option><option v-for="account in availableAccounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.display_phone_number }})</option></select></div>
            <div class="md:col-span-2"><label class="form-label">Template da Mensagem</label><select v-model="form.template_name" @change="onTemplateSelect" :disabled="!form.whatsapp_account_id || templatesLoading" required class="form-input"><option v-if="templatesLoading" value="">Carregando...</option><option v-else-if="!availableTemplates.length && form.whatsapp_account_id" :value="null" disabled>Nenhum template aprovado</option><option v-else :value="null" disabled>Selecione um template</option><option v-for="template in availableTemplates" :key="template.name" :value="template.name">{{ template.name }} ({{ template.category }})</option></select></div>
          </div>
          <div v-if="templateVariables.length > 0" class="space-y-4 p-4 border rounded-md bg-gray-50"><h4 class="font-medium text-gray-800">Variáveis do Template</h4><div v-for="variable in templateVariables" :key="variable" class="grid grid-cols-1 md:grid-cols-2 gap-2 items-center"><label class="form-label md:col-span-1">Variável <code class="text-sm bg-gray-200 px-1 py-0.5 rounded">{{variable}}</code></label><div class="md:col-span-1"><select v-model="form.template_parameters[variable]" class="form-input text-sm"><option value="name">Nome do Contato</option><option value="phone_number">Telefone</option><option value="custom.cep">CEP (custom)</option><option value="custom.cidade">Cidade (custom)</option></select></div></div></div>
          
          <div v-if="templateHasMediaHeader" class="space-y-3 p-4 border rounded-md bg-gray-50">
            <h4 class="font-medium text-gray-800">Mídia da Campanha (Header)</h4>
            <div v-if="form.media.length > 0">
              <div v-for="(mediaItem, index) in form.media" :key="index" class="flex items-center space-x-3 bg-gray-100 p-2 rounded-lg">
                
                <div class="flex-shrink-0">
                    <img 
                      v-if="getMediaTypeFromUrl(mediaItem.url) === 'image'" 
                      :src="mediaItem.url" 
                      class="h-16 w-16 object-cover rounded-md border" 
                      alt="Preview da Imagem"
                    />
                    <video 
                      v-else-if="getMediaTypeFromUrl(mediaItem.url) === 'video'" 
                      :src="mediaItem.url" 
                      class="h-16 w-16 object-cover rounded-md border bg-black" 
                      muted 
                      playsinline 
                      loop
                      autoplay
                    ></video>
                    <div v-else class="h-16 w-16 rounded-md border bg-gray-200 flex items-center justify-center">
                        <DocumentTextIcon class="h-8 w-8 text-gray-500" />
                    </div>
                </div>
                
                <div class="flex-grow">
                    <p class="text-sm font-medium text-gray-800 truncate">{{ mediaItem.file_name }}</p>
                    <p class="text-xs text-gray-500">{{ getMediaTypeFromUrl(mediaItem.url) }}</p>
                </div>

                <button type="button" @click="removeMedia(index)" class="text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100">
                    <XMarkIcon class="h-5 w-5" />
                </button>
              </div>
            </div>
            <input type="file" @change="handleMediaUpload" class="form-input" />
          </div>

          <div class="space-y-4 p-4 border rounded-md bg-gray-50">
            <h4 class="font-medium text-gray-800">Segmentação de Contatos (VotaBox)</h4>
            <div v-if="votaboxLoading" class="text-center text-gray-500">Carregando dados de segmentação...</div>
            <div v-else class="space-y-4">
                <div>
                    <label class="form-label">Tags</label>
                    <div class="max-h-32 overflow-y-auto border rounded-md p-2 space-y-1">
                        <div v-for="tag in availableVotaboxTags" :key="tag.id" class="flex items-center">
                            <input type="checkbox" :id="'tag-'+tag.id" :value="tag.id" v-model="form.votabox_filters.tag_ids" class="h-4 w-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                            <label :for="'tag-'+tag.id" class="ml-2 text-sm text-gray-700">{{ tag.label }}</label>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label">Pesquisas</label>
                    <div class="max-h-48 overflow-y-auto border rounded-md p-2 space-y-2">
                        <div v-for="survey in availableVotaboxSurveys" :key="survey.id">
                            <div class="flex items-center">
                                <input type="checkbox" :id="'survey-'+survey.id" @change="toggleSurveySelection(survey.id)" :checked="isSurveySelected(survey.id)" class="h-4 w-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                                <label :for="'survey-'+survey.id" class="ml-2 text-sm font-medium text-gray-800">{{ survey.title }}</label>
                            </div>
                            
                            <div v-if="isSurveySelected(survey.id)" class="ml-6 mt-2 space-y-3 pt-3 border-t">
                                <div v-for="question in getSurveyDetails(survey.id)?.questions" :key="question.guid" class="p-2 border rounded-md bg-white">
                                    <p class="font-medium text-xs text-gray-600 mb-1">{{ question.title }}</p>
                                    <div class="grid grid-cols-2 gap-1">
                                        <div v-for="option in question.options" :key="option.index" class="flex items-center">
                                            <input type="checkbox" :id="survey.id+'-'+question.guid+'-'+option.index" @change="toggleSurveyQuestion(survey.id, question.guid, option.label)" :checked="isQuestionAnswerSelected(survey.id, question.guid, option.label)" class="h-4 w-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                                            <label :for="survey.id+'-'+question.guid+'-'+option.index" class="ml-2 text-xs text-gray-700">{{ option.label }}</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
          </div>

          <div class="flex justify-end space-x-3 pt-4 border-t mt-6">
            <button v-if="form.id" type="button" @click="fetchContactsPreview" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Ver Contatos</button>
            <button type="button" @click="previewMessage" :disabled="!form.template_name" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md text-sm disabled:bg-gray-300">Pré-visualizar</button>
            <button type="button" @click="showModal = false" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Cancelar</button>
            <button type="submit" :disabled="isSaving" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md disabled:bg-gray-400">{{ isSaving ? 'Salvando...' : 'Salvar Campanha' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showPreviewModal" class="fixed inset-0 bg-black bg-opacity-50 z-[60] flex items-center justify-center" @click="showPreviewModal = false">
      <div class="bg-transparent p-4 w-full max-w-sm" @click.stop>
        <div class="bg-[#dcf8c6] text-black rounded-lg p-3 relative shadow-lg" style="width: fit-content; max-width: 100%;">
          <div class="absolute -top-2 -right-2 w-0 h-0 border-l-[10px] border-l-transparent border-t-[10px] border-t-[#dcf8c6]"></div>
          <div v-if="previewContent.mediaUrl" class="mb-2">
            <img :src="previewContent.mediaUrl" class="rounded-md w-full object-cover" alt="Preview da Mídia"/>
          </div>
          <p class="text-sm whitespace-pre-wrap">{{ previewContent.text }}</p>
          <div class="text-right text-xs text-gray-500 mt-1">
            <span>11:45</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showContactsPreviewModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 z-[60] flex items-center justify-center" @click="showContactsPreviewModal = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6" @click.stop>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Contatos da Campanha</h3>
        <div class="max-h-96 overflow-y-auto">
          <div v-if="contactsPreviewLoading" class="text-center py-4">Carregando...</div>
          <div v-else>
            <ul v-if="previewContacts.length > 0" class="divide-y divide-gray-200">
              <li v-for="contact in previewContacts" :key="contact.id" class="py-3 flex justify-between">
                <span class="text-gray-800">{{ contact.name }}</span>
                <span class="text-gray-500">{{ contact.phone_number }}</span>
              </li>
            </ul>
            <p v-else class="text-gray-500 text-center py-4">Nenhum contato encontrado para esta campanha.</p>
            <p class="text-xs text-gray-400 mt-2 text-center" v-if="previewContacts.length > 0">Mostrando os contatos da campanha salva.</p>
          </div>
        </div>
        <div class="text-right mt-6">
          <button @click="showContactsPreviewModal = false" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Fechar</button>
        </div>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon, MegaphoneIcon, XMarkIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
  campaigns: Object,
  filters: Object,
});

const showModal = ref(false);
const availableAccounts = ref([]);
const availableTemplates = ref([]);
const templatesLoading = ref(false);
const isSaving = ref(false);
const showPreviewModal = ref(false);
const previewContent = ref({ text: '', mediaUrl: null });
const showContactsPreviewModal = ref(false);
const contactsPreviewLoading = ref(false);
const previewContacts = ref([]);

const availableVotaboxTags = ref([]);
const availableVotaboxSurveys = ref([]);
const votaboxLoading = ref(false);

const filterForm = ref({ search: props.filters.search || '', status: props.filters.status || '' });

const form = useForm({
    id: null,
    name: '',
    description: '',
    type: 'scheduled',
    scheduled_at: null,
    whatsapp_account_id: null,
    template_name: null,
    template_parameters: {},
    media: [],
    votabox_filters: {
        tag_ids: [],
        surveys: []
    }
});

watch(() => form.type, (newType) => { if (newType === 'immediate') form.scheduled_at = null; });
watch(filterForm, debounce(() => router.get(route('campaigns'), filterForm.value, { preserveState: true, replace: true }), 300), { deep: true });

const openModal = async (campaign = null) => {
    form.reset();
    form.clearErrors();
    showModal.value = true;
    votaboxLoading.value = true;
    
    await Promise.all([
        fetchAccounts(),
        fetchVotaboxData()
    ]);

    if (campaign) {
        try {
            const response = await axios.get(route('api.campaigns.show', campaign.id));
            const fullCampaign = response.data.campaign;
            
            form.id = fullCampaign.id;
            form.name = fullCampaign.name;
            form.description = fullCampaign.description;
            form.type = fullCampaign.scheduled_at ? 'scheduled' : 'immediate';
            form.scheduled_at = fullCampaign.scheduled_at ? fullCampaign.scheduled_at.substring(0, 16) : null;
            form.whatsapp_account_id = fullCampaign.whatsapp_account_id;
            if (fullCampaign.template_parameters?.header?.type === 'media' && fullCampaign.template_parameters?.header?.url) {
                form.media.push({ url: fullCampaign.template_parameters.header.url, file_name: 'Mídia da Campanha' });
            }
            // ### CORREÇÃO PRINCIPAL AQUI ###
            // Garante que o votabox_filters seja um objeto, decodificando se for uma string.
            let filters = fullCampaign.votabox_filters;
            if (filters && typeof filters === 'string') {
                try {
                    filters = JSON.parse(filters);
                } catch (e) {
                    console.error("Falha ao decodificar votabox_filters:", e);
                    filters = null; // Reseta se a decodificação falhar
                }
            }
            form.votabox_filters = filters || { tag_ids: [], surveys: [] };
            // ### FIM DA CORREÇÃO ###

            let params = fullCampaign.template_parameters;
            if (params && typeof params === 'string') {
                 try {
                    params = JSON.parse(params);
                } catch (e) {
                    params = {};
                }
            }
            form.template_parameters = params || {};
            
            if (form.whatsapp_account_id) {
                await fetchTemplates();
                form.template_name = fullCampaign.template_name;
            }

        } catch (error) {
            console.error("Erro ao carregar dados da campanha para edição:", error);
            alert('Falha ao carregar dados da campanha.');
            showModal.value = false;
        }
    }
    
    votaboxLoading.value = false;
};

const getMediaTypeFromUrl = (url) => {
    if (!url) return 'unknown';
    
    // Pega a parte da URL antes de qualquer parâmetro de query '?'
    const path = url.split('?')[0];
    
    // Extrai a extensão do arquivo
    const extension = path.split('.').pop().toLowerCase();

    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(extension)) {
        return 'image';
    }
    if (['mp4', 'webm', 'ogg'].includes(extension)) {
        return 'video';
    }
    if (['pdf', 'doc', 'docx', 'xls', 'xlsx'].includes(extension)) {
        return 'document';
    }
    
    return 'unknown'; // Fallback para outros tipos
};

const fetchVotaboxData = async () => {
    try {
        const [tagsRes, surveysRes] = await Promise.all([
            axios.get(route('api.votabox.tags')),
            axios.get(route('api.votabox.surveys'))
        ]);
        availableVotaboxTags.value = tagsRes.data;
        availableVotaboxSurveys.value = surveysRes.data;
    } catch (error) {
        console.error("Erro ao buscar dados da VotaBox:", error);
        alert('Não foi possível carregar os dados de segmentação da VotaBox.');
    }
};

const toggleSurveySelection = (surveyId) => {
    const surveyIndex = form.votabox_filters.surveys.findIndex(s => s.survey_id === surveyId);
    if (surveyIndex > -1) {
        form.votabox_filters.surveys.splice(surveyIndex, 1);
    } else {
        form.votabox_filters.surveys.push({ survey_id: surveyId, questions: [] });
    }
};

const toggleSurveyQuestion = (surveyId, guid, answer) => {
    const surveyFilter = form.votabox_filters.surveys.find(s => s.survey_id === surveyId);
    if (!surveyFilter) return;
    
    // Procura pela combinação exata de guid e answer (string)
    const questionIndex = surveyFilter.questions.findIndex(q => q.guid === guid && q.answer === answer);

    if (questionIndex > -1) {
        // Se já existe, remove
        surveyFilter.questions.splice(questionIndex, 1);
    } else {
        // Se não existe, adiciona
        surveyFilter.questions.push({ guid, answer });
    }
};


const isSurveySelected = (surveyId) => {
    if (!form.votabox_filters || !form.votabox_filters.surveys) return false;
    return form.votabox_filters.surveys.some(s => s.survey_id === surveyId);
};

const getSurveyDetails = (surveyId) => {
    return availableVotaboxSurveys.value.find(s => s.id === surveyId);
};

const isQuestionAnswerSelected = (surveyId, guid, answer) => {
    const surveyFilter = form.votabox_filters.surveys.find(s => s.survey_id === surveyId);
    if (!surveyFilter || !surveyFilter.questions) return false;
    
    // Verifica a combinação exata de guid e answer (string)
    return surveyFilter.questions.some(q => q.guid === guid && q.answer === answer);
};

const saveCampaign = async () => {
    isSaving.value = true;
    
    const url = form.id ? route("api.campaigns.update", form.id) : route("api.campaigns.store");
    const formData = new FormData();

    const finalVotaboxFilters = {
        ...form.votabox_filters,
        surveys: form.votabox_filters.surveys.filter(s => s.questions && s.questions.length > 0)
    };
    
    // Adiciona os dados ao FormData
    formData.append('name', form.name);
    formData.append('type', form.type);
    formData.append('whatsapp_account_id', form.whatsapp_account_id);
    formData.append('template_name', form.template_name);
    if(form.description) formData.append('description', form.description);
    if(form.scheduled_at) formData.append('scheduled_at', form.scheduled_at);

    // Garante que o objeto de parâmetros seja enviado corretamente
    formData.append('template_parameters', JSON.stringify(form.template_parameters || {}));
    formData.append('votabox_filters', JSON.stringify(finalVotaboxFilters));

    if (form.media.length > 0 && form.media[0].file) {
        formData.append('header_media', form.media[0].file);
    }
    
    // Para o método PUT do Laravel, usamos um POST com o campo _method
    if (form.id) {
        formData.append("_method", "PUT");
    }

    try {
        // Usa sempre axios.post, que lida melhor com FormData
        await axios.post(url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });
        showModal.value = false;
        router.reload({ only: ["campaigns"] });
    } catch (error) {
        if (error.response && error.response.status === 422) {
            form.setError(error.response.data.errors);
            const errorMessages = Object.values(error.response.data.errors).flat().join('\n');
            alert(`Por favor, verifique os erros no formulário:\n${errorMessages}`);
        } else {
            alert(`Ocorreu um erro: ${error.response?.data?.message || "Verifique os dados."}`);
        }
    } finally {
        isSaving.value = false;
    }
};

const fetchAccounts = async () => { try { const response = await axios.get(route('api.whatsapp-accounts.index')); availableAccounts.value = response.data.accounts; } catch (error) { console.error("Erro ao buscar contas:", error); } };
const fetchTemplates = async () => { if (!form.whatsapp_account_id) return; templatesLoading.value = true; availableTemplates.value = []; try { const response = await axios.get(route('api.campaigns.templates', { whatsapp_account_id: form.whatsapp_account_id })); if (response.data.success) { availableTemplates.value = response.data.data; } else { throw new Error(response.data.message); } } catch (error) { console.error("Erro ao buscar templates:", error); } finally { templatesLoading.value = false; } };
const onAccountChange = () => { form.template_name = null; form.template_parameters = {}; fetchTemplates(); };
const onTemplateSelect = () => {
    // Não limpa mais os parâmetros de cara, para preservar os dados da edição.
    // form.template_parameters = {}; <-- LINHA REMOVIDA

    const template = availableTemplates.value.find(t => t.name === form.template_name);
    if (template) {
        // Itera sobre as variáveis encontradas no template
        templateVariables.value.forEach(variable => {
            // SÓ define um valor padrão se um já não existir.
            // Isso corrige o problema de apagar os dados no modo de edição.
            if (!form.template_parameters[variable]) {
                form.template_parameters[variable] = 'name'; // Valor padrão
            }
        });

        const hasMedia = template.components.some(c => c.type === 'HEADER' && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(c.format));

        // console.log("Template selecionado:", template.name, "Tem mídia de cabeçalho:", hasMedia);
        if (!hasMedia) {
            form.media = [];
        }
    }
};

const previewMessage = () => {
    const template = availableTemplates.value.find(t => t.name === form.template_name);
    if (!template) { alert("Selecione um template primeiro."); return; }
    let bodyText = '';
    const bodyComponent = template.components.find(c => c.type === 'BODY');
    if (bodyComponent && bodyComponent.text) {
        bodyText = bodyComponent.text.replace(/\{\{([0-9]+)\}\}/g, (match, p1) => {
            const paramValue = form.template_parameters[p1];
            if (paramValue === 'name') return '[Nome do Contato]';
            if (paramValue === 'phone_number') return '[Telefone]';
            return `[${paramValue || `Variável ${p1}`}]`;
        });
    }

    let mediaUrl = null;
    if (templateHasMediaHeader.value && form.media.length > 0) {
        mediaUrl = form.media[0].url;
    }

    previewContent.value = { text: bodyText, mediaUrl };
    showPreviewModal.value = true;
};

const fetchContactsPreview = async () => {
    if (!form.id) return;
    showContactsPreviewModal.value = true;
    contactsPreviewLoading.value = true;
    previewContacts.value = [];
    try {
        const response = await axios.get(route('api.campaigns.contacts', form.id));
        if (response.data.success) {
            previewContacts.value = response.data.contacts.data;
        } else {
            alert("Erro ao buscar contatos: " + response.data.message);
        }
    } catch (error) {
        console.error("Erro ao buscar contatos para preview:", error);
        alert("Ocorreu um erro de rede ao buscar os contatos.");
    } finally {
        contactsPreviewLoading.value = false;
    }
};

const performAction = async (action, campaign) => {
    if(action === 'delete' && !confirm('Tem certeza que deseja excluir esta campanha?')) return;
    try {
        await axios.post(route(`api.campaigns.${action}`, campaign.id));
        router.reload({ only: ['campaigns'], preserveScroll: true });
    } catch (error) {
        alert(`Não foi possível executar a ação: ${error.response?.data?.message || 'Erro desconhecido'}`);
    }
};

const handleMediaUpload = (event) => { const file = event.target.files[0]; if (file) { form.media = [{ file, url: URL.createObjectURL(file), file_name: file.name }]; } };
const removeMedia = (index) => { form.media.splice(index, 1); };
const templateHasMediaHeader = computed(() => { const template = availableTemplates.value.find(t => t.name === form.template_name); if (!template) return false; return template.components.some(c => c.type === 'HEADER' && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(c.format)); });
const templateVariables = computed(() => { const template = availableTemplates.value.find(t => t.name === form.template_name); if (!template) return []; const variables = new Set(); template.components?.forEach(component => { if (component.text) { const matches = component.text.match(/\{\{([0-9]+)\}\}/g); if (matches) { matches.forEach(match => variables.add(match.replace(/\{|\}/g, ''))); } } }); return Array.from(variables).sort((a, b) => a - b); });
const getProgress = (campaign) => { if (!campaign.total_contacts) return 0; const processed = campaign.sent_count + campaign.failed_count; return (processed / campaign.total_contacts) * 100; };
const getStatusText = (status) => ({ 'draft': 'Rascunho', 'scheduled': 'Agendada', 'running': 'Executando', 'paused': 'Pausada', 'completed': 'Concluída', 'cancelled': 'Cancelada' }[status] || status);
const getStatusClass = (status) => ({ 'draft': 'bg-gray-100 text-gray-800', 'scheduled': 'bg-blue-100 text-blue-800', 'running': 'bg-green-100 text-green-800', 'paused': 'bg-yellow-100 text-yellow-800', 'completed': 'bg-purple-100 text-purple-800', 'cancelled': 'bg-red-100 text-red-800' }[status] || 'bg-gray-100 text-gray-800');

</script>