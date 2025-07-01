class EmailMarketingApp {
    constructor() {
        this.currentPage = 1;
        this.currentSearch = '';
        this.currentSection = 'dashboard';
        this.currentStep = 1;
        this.apiUrl = 'https://marketing.dom0125.com/api.php';
        this.contacts = JSON.parse('[]');
        this.currentSection = 'dashboard';
        this.currentImportStep = 1;
        this.currentListId = 'all';
        this.contactLists = JSON.parse('[]');
        this.contactListMembers = JSON.parse('[]');


        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadDashboard();
        this.loadSenders();
        this.loadContacts();
        this.loadCampaigns();
        this.updateStats();
        // this.loadContactLists();
    }


    setupEventListeners() {
        // Navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const section = e.currentTarget.dataset.section;
                this.switchSection(section);
            });
        });

        // Modal controls
        document.querySelectorAll('.modal-close, [data-modal]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.currentTarget.dataset.modal ||
                    e.currentTarget.closest('.modal').id;
                this.closeModal(modal);
            });
        });

        // Database connection test
        //  document.getElementById('test-db-connection').addEventListener('click', () => {
        //   this.testDatabaseConnection();
        //});

        // Sender management
        document.getElementById('add-sender-btn').addEventListener('click', () => {
            this.openModal('sender-modal');
        });

        document.getElementById('add-first-sender').addEventListener('click', () => {
            this.openModal('sender-modal');
        });

        document.getElementById('sender-form').addEventListener('submit', (e) => {
            this.handleSenderSubmit(e);
        });

        // Contact management
        document.getElementById('add-contact-btn').addEventListener('click', () => {
            this.openModal('contact-modal');
        });

        document.getElementById('contact-form').addEventListener('submit', (e) => {
            this.handleContactSubmit(e);
        });

        document.getElementById('search-contacts').addEventListener('input', (e) => {
            this.filterContacts();
        });

        document.getElementById('filter-status').addEventListener('change', (e) => {
            this.filterContacts();
        });

        document.getElementById('filter-list').addEventListener('change', (e) => {
            this.filterContacts();
        });

        // Bulk actions
        document.getElementById('select-all-contacts').addEventListener('change', (e) => {
            this.toggleSelectAll(e.target.checked);
        });

        document.getElementById('bulk-add-to-list').addEventListener('click', () => {
            this.bulkAddToList();
        });

        document.getElementById('bulk-remove-from-list').addEventListener('click', () => {
            this.bulkRemoveFromList();
        });

        document.getElementById('bulk-delete').addEventListener('click', () => {
            this.bulkDeleteContacts();
        });

        // Import functionality
        document.getElementById('import-contacts-btn').addEventListener('click', () => {
            this.openModal('import-modal');
        });

        document.getElementById('csv-file').addEventListener('change', (e) => {
            this.handleFileSelect(e);
        });




        // Campaign management
        document.getElementById('new-campaign-btn').addEventListener('click', () => {
            this.openCampaignModal();
        });

        document.getElementById('create-campaign-btn').addEventListener('click', () => {
            this.openCampaignModal();
        });

        document.getElementById('campaign-form').addEventListener('submit', (e) => {
            this.handleCampaignSubmit(e);
        });

        // Campaign steps
        document.getElementById('next-step').addEventListener('click', () => {
            this.nextStep();
        });

        document.getElementById('prev-step').addEventListener('click', () => {
            this.prevStep();
        });

        // Email editor
        document.querySelectorAll('.toolbar-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.handleEditorCommand(e.currentTarget.dataset.command);
            });
        });

        // Test email
        document.getElementById('test-email').addEventListener('click', () => {
            this.openModal('test-email-modal');
        });

        document.getElementById('send-test-email').addEventListener('click', () => {
            this.sendTestEmail();
        });
    }

    // --- NUEVOS MÉTODOS Y MÉTODOS ACTUALIZADOS ---filter

    /**
     * Cambia entre la vista de 'Contactos' y 'Listas'
     */
    switchView(view) {
        this.currentView = view;
        const contactsView = document.getElementById('contacts-view');
        const listsView = document.getElementById('lists-view');
        const createListBtn = document.getElementById('create-list-btn');
        const tabContacts = document.getElementById('tab-contacts');
        const tabLists = document.getElementById('tab-lists');

        if (view === 'lists') {
            contactsView.style.display = 'none';
            listsView.style.display = 'block';
            createListBtn.style.display = 'inline-block';
            tabContacts.classList.remove('active');
            tabLists.classList.add('active');
            this.loadLists();
        } else { // 'contacts'
            contactsView.style.display = 'block';
            listsView.style.display = 'none';
            createListBtn.style.display = 'none';
            tabContacts.classList.add('active');
            tabLists.classList.remove('active');
            this.loadContacts(1, "");
            //this.loadContacts();
        }
    }


    async apiRequest(endpoint, method = 'GET', data = null) {
        try {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                },
            };

            if (data) {
                options.body = JSON.stringify(data);
            }
            const url = `${this.apiUrl.replace(/\/api\.php$/, '')}/api.php/${endpoint}`;
            const response = await fetch(url, options);
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'API request failed');
            }

            return result.data;
        } catch (error) {
            this.showToast('error', 'Error de conexión', error.message);
            throw error;
        }
    }

    async testDatabaseConnection() {
        try {
            this.showToast('info', 'Probando conexión', 'Verificando conexión a la base de datos...');
            const url = `${this.apiUrl.replace(/\/api\.php$/, '')}/api.php/test-connection`;
            const result = await fetch(url);
            console.log(result.status);

            if (result.status == 200) {
                this.showToast('success', 'Conexión exitosa', 'La conexión a la base de datos funciona correctamente.');
                this.loadDashboard();
                this.loadSenders();
                this.loadContacts();
                this.loadCampaigns();
                this.updateStats();
            } else {
                this.showToast('error', 'Error de conexión', data.error || 'No se pudo conectar a la base de datos.');
            }
        } catch (error) {
            console.error('Error al conectar:', error);
            this.showToast('error', 'Error de conexión', 'No se pudo verificar la conexión a la base de datos.');
        }
    }

    switchSection(section) {
        // Update navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
        });
        document.querySelector(`[data-section="${section}"]`).classList.add('active');

        // Update content
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });
        document.getElementById(`${section}-section`).classList.add('active');

        // Update page title
        const titles = {
            dashboard: 'Dashboard',
            senders: 'Configurar Remitentes',
            contacts: 'Gestión de Contactos',
            campaigns: 'Campañas de Email',
            templates: 'Plantillas de Email'
        };
        document.getElementById('page-title').textContent = titles[section];

        this.currentSection = section;
    }

    openModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';

        // Reset forms
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
        }

        // Reset campaign steps
        if (modalId === 'campaign-modal') {
            this.resetCampaignModal();
        }
    }

    async handleSenderSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const sender = Object.fromEntries(formData.entries());

        try {
            await this.apiRequest('senders', 'POST', sender);
            this.loadSenders();
            this.closeModal('sender-modal');
            this.showToast('success', '¡Remitente agregado!', 'El remitente se ha configurado correctamente.');
        } catch (error) {
            // Error already handled in apiRequest
        }
    }

    async handleContactSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const contact = Object.fromEntries(formData.entries());

        // Check if email already exists
        if (this.contacts.some(c => c.email === contact.email)) {
            this.showToast('error', 'Email duplicado', 'Ya existe un contacto con ese email.');
            return;
        }

        contact.id = Date.now();
        contact.created_at = new Date().toISOString();

        try {
            await this.apiRequest('contacts', 'POST', contact);
            this.loadContacts();
            this.closeModal('contact-modal');
            this.updateStats();
            this.showToast('success', '¡Contacto agregado!', 'El contacto se ha guardado correctamente.');
        } catch (error) {
            // Error already handled in apiRequest
        }
    }

    async handleCsvImport(e) {
        e.preventDefault();

        const fileInput = document.getElementById('csv-file');
        const file = fileInput.files[0];

        if (!file) {
            this.showToast('error', 'Archivo requerido', 'Por favor selecciona un archivo CSV.');
            return;
        }

        const formData = new FormData();
        formData.append('csv_file', file);

        try {
            const response = await fetch(`${this.apiUrl}/contacts?import=1`, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                this.loadContacts();
                this.updateStats();
                this.closeModal('import-csv-modal');

                const message = `${result.data.imported} contactos importados correctamente.`;
                const errorMessage = result.data.errors.length > 0 ?
                    ` ${result.data.errors.length} errores encontrados.` : '';

                this.showToast('success', 'Importación completada', message + errorMessage);

                if (result.data.errors.length > 0) {
                    console.warn('Import errors:', result.data.errors);
                }
            } else {
                this.showToast('error', 'Error de importación', result.error);
            }
        } catch (error) {
            this.showToast('error', 'Error de importación', 'No se pudo importar el archivo CSV.');
        }
    }

    async handleCampaignSubmit(e) {
        e.preventDefault();

        const campaign = {
            name: document.getElementById('campaign-name').value,
            sender_id: document.getElementById('campaign-sender').value,
            subject: document.getElementById('campaign-subject').value,
            html_content: document.getElementById('email-editor').innerHTML,
        };

        try {
            const result = await this.apiRequest('campaigns', 'POST', campaign);

            // Send the campaign
            await this.apiRequest(`send-campaign/${result.id}`, 'POST');

            this.loadCampaigns();
            this.updateStats();
            this.closeModal('campaign-modal');
            this.showToast('success', '¡Campaña enviada!', `La campaña "${campaign.name}" se ha enviado correctamente.`);
        } catch (error) {
            // Error already handled in apiRequest
        }
    }

    async loadSenders() {
        try {
            const senders = await this.apiRequest('senders');
            const sendersList = document.getElementById('senders-list');
            const campaignSenderSelect = document.getElementById('campaign-sender');

            if (senders.length === 0) {
                sendersList.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-plus"></i>
                        <p>No hay remitentes configurados</p>
                        <button class="btn btn-outline" id="add-first-sender">Agregar Primer Remitente</button>
                    </div>
                `;

                // Re-attach event listener
                document.getElementById('add-first-sender').addEventListener('click', () => {
                    this.openModal('sender-modal');
                });

                // Clear campaign sender options
                campaignSenderSelect.innerHTML = '<option value="">Seleccionar remitente...</option>';
            } else {
                sendersList.innerHTML = senders.map(sender => `
                    <div class="sender-item">
                        <div class="sender-info">
                            <h4>${sender.name}</h4>
                            <p>${sender.email}</p>
                            <small>SMTP: ${sender.smtp_host}:${sender.smtp_port}</small>
                        </div>
                        <div class="sender-actions">
                            <button class="btn btn-sm btn-outline" onclick="emailApp.editSender(${sender.id})">
                                <i class="fas fa-edit"></i>
                                Editar
                            </button>
                            <button class="btn btn-sm btn-outline" onclick="emailApp.deleteSender(${sender.id})">
                                <i class="fas fa-trash"></i>
                                Eliminar
                            </button>
                        </div>
                    </div>
                `).join('');

                // Update campaign sender select
                campaignSenderSelect.innerHTML = `
                    <option value="">Seleccionar remitente...</option>
                    ${senders.map(sender =>
                    `<option value="${sender.id}">${sender.name} (${sender.email})</option>`
                ).join('')}
                `;
            }
        } catch (error) {
            document.getElementById('senders-list').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error al cargar remitentes</p>
                    <button class="btn btn-outline" onclick="emailApp.loadSenders()">Reintentar</button>
                </div>
            `;
        }
    }

    async loadCampaigns() {
        try {
            const campaigns = await this.apiRequest('campaigns');
            const campaignGrid = document.getElementById('campaigns-grid');

            if (campaigns.length === 0) {
                campaignGrid.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-rocket"></i>
                    <p>No hay campañas creadas</p>
                    <button class="btn btn-outline" onclick="emailApp.openCampaignModal()">Crear Primera Campaña</button>
                </div>
            `;
            } else {
                campaignGrid.innerHTML = campaigns.map(campaign => `
                <div class="card">
                    <div style="padding: 1.5rem;">
                        <h3>${campaign.name}</h3>
                        <p><strong>Asunto:</strong> ${campaign.subject}</p>
                        <p><strong>Remitente:</strong> ${campaign.sender_name || 'N/A'}</p>
                        
                        <p><strong>Enviados:</strong> ${campaign.total_sent || 0} de ${campaign.total_recipients || 0} destinatarios</p>
                        <p><strong>Estado:</strong> ${campaign.status}</p>
                        <p><strong>Iniciada:</strong> ${campaign.sent_at ? new Date(campaign.sent_at).toLocaleDateString() : 'Pendiente'}</p>
                        
                        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                            <div>
                                <strong>${campaign.open_rate || 0}%</strong>
                                <small style="display: block; color: var(--text-secondary);">Apertura (${campaign.total_opened || 0})</small>
                            </div>
                            <div>
                                <strong>${campaign.click_rate || 0}%</strong>
                                <small style="display: block; color: var(--text-secondary);">Clicks (${campaign.total_clicked || 0})</small>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
            }
        } catch (error) {
            console.error("Error al cargar campañas:", error); // Añadido para depuración
            document.getElementById('campaigns-grid').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error al cargar campañas</p>
                <button class="btn btn-outline" onclick="emailApp.loadCampaigns()">Reintentar</button>
            </div>
        `;
        }
    }

    async loadDashboard() {
        try {
            const campaigns = await this.apiRequest('campaigns');

            // Load recent campaigns
            const recentCampaigns = document.getElementById('recent-campaigns');
            if (!recentCampaigns) {
                console.warn('recent-campaigns element not found');
                return;
            }

            const recentCampaignsList = campaigns.slice(-5).reverse();

            if (recentCampaignsList.length === 0) {
                recentCampaigns.innerHTML = '<p class="empty-state">No hay campañas recientes</p>';
            } else {
                recentCampaigns.innerHTML = recentCampaignsList.map(campaign => `
                    <div style="padding: 1rem; border-bottom: 1px solid var(--border-color);">
                        <h4 style="margin-bottom: 0.5rem;">${campaign.name}</h4>
                        <p style="color: var(--text-secondary); font-size: 0.875rem;">
                            ${campaign.sent_at ? new Date(campaign.sent_at).toLocaleDateString() : 'Borrador'} • ${campaign.total_recipients || 0} destinatarios
                        </p>
                    </div>
                `).join('');
            }

            // Load activity feed - simplified for now
            //const activityFeed = document.getElementById('activity-feed');
            //activityFeed.innerHTML = '<p class="empty-state">Actividad disponible pronto</p>';

        } catch (error) {
            const recentCampaigns = document.getElementById('recent-campaigns');
            if (recentCampaigns) {
                recentCampaigns.innerHTML = '<p class="empty-state">Error al cargar datos</p>';
            }
            console.error('Error loading dashboard:', error);
        }
    }

    async updateStats() {
        try {
            const stats = await this.apiRequest('stats');

            document.getElementById('campaigns-count').textContent = stats.total_campaigns || 0;
            document.getElementById('contacts-count').textContent = stats.total_contacts || 0;
            document.getElementById('open-rate').textContent = (stats.avg_open_rate || 0) + '%';
            document.getElementById('click-rate').textContent = (stats.avg_click_rate || 0) + '%';
        } catch (error) {
            // Keep default values on error
        }
    }


    async openCampaignModal() {
        try {
            const senders = await this.apiRequest('senders');
            const contacts = await this.apiRequest('contacts');
            const activeContacts = this.contacts ? this.contacts.filter(c => c.status === 'active') : [];
            if (senders.length === 0) {
                this.showToast('warning', 'Sin remitentes', 'Primero debes configurar al menos un remitente.');
                return;
            }

            if (activeContacts.length === 0) {
                this.showToast('warning', 'Sin contactos', 'Primero debes agregar contactos activos.');
                return;
            }

            this.openModal('campaign-modal');
        } catch (error) {
            this.showToast('error', 'Error', 'No se pudo abrir el modal de campaña.');
        }
    }

    resetCampaignModal() {
        this.currentStep = 1;

        // Reset steps
        document.querySelectorAll('.step').forEach(step => step.classList.remove('active'));
        document.querySelector('[data-step="1"]').classList.add('active');

        document.querySelectorAll('.step-content').forEach(content => content.classList.remove('active'));
        document.getElementById('step-1').classList.add('active');

        // Reset buttons
        document.getElementById('prev-step').style.display = 'none';
        document.getElementById('next-step').style.display = 'inline-flex';
        document.getElementById('send-campaign').style.display = 'none';

        // Reset editor
        document.getElementById('email-editor').innerHTML = '<p>Escribe tu mensaje aquí...</p>';
    }

    nextStep() {
        if (this.currentStep < 3) {
            // Validate current step
            if (!this.validateStep(this.currentStep)) {
                return;
            }

            this.currentStep++;
            this.updateCampaignStep();

            if (this.currentStep === 3) {
                this.updateCampaignSummary();
            }
        }
    }

    prevStep() {
        if (this.currentStep > 1) {
            this.currentStep--;
            this.updateCampaignStep();
        }
    }

    validateStep(step) {
        if (step === 1) {
            const name = document.getElementById('campaign-name').value;
            const sender = document.getElementById('campaign-sender').value;
            const subject = document.getElementById('campaign-subject').value;

            if (!name || !sender || !subject) {
                this.showToast('error', 'Campos requeridos', 'Por favor completa todos los campos.');
                return false;
            }
        } else if (step === 2) {
            const content = document.getElementById('email-editor').innerHTML.trim();
            if (!content || content === '<p>Escribe tu mensaje aquí...</p>') {
                this.showToast('error', 'Contenido requerido', 'Por favor escribe el contenido del email.');
                return false;
            }
        }
        return true;
    }

    updateCampaignStep() {
        // Update step indicators
        document.querySelectorAll('.step').forEach(step => step.classList.remove('active'));
        document.querySelector(`[data-step="${this.currentStep}"]`).classList.add('active');

        // Update step content
        document.querySelectorAll('.step-content').forEach(content => content.classList.remove('active'));
        document.getElementById(`step-${this.currentStep}`).classList.add('active');

        // Update buttons
        document.getElementById('prev-step').style.display = this.currentStep > 1 ? 'inline-flex' : 'none';
        document.getElementById('next-step').style.display = this.currentStep < 3 ? 'inline-flex' : 'none';
        document.getElementById('send-campaign').style.display = this.currentStep === 3 ? 'inline-flex' : 'none';
    }

    updateCampaignSummary() {
        const name = document.getElementById('campaign-name').value;
        const senderId = document.getElementById('campaign-sender').value;
        const sender = this.senders.find(s => s.id == senderId);
        const subject = document.getElementById('campaign-subject').value;
        const activeContacts = this.contacts.filter(c => c.status === 'active').length;

        document.getElementById('summary-name').textContent = name;
        document.getElementById('summary-sender').textContent = sender ? `${sender.name} (${sender.email})` : '';
        document.getElementById('summary-subject').textContent = subject;
        document.getElementById('summary-recipients').textContent = activeContacts;

        // Update preview with proper HTML rendering
        const previewContainer = document.getElementById('email-preview-content');
        const emailContent = document.getElementById('email-editor').innerHTML;

        // Clear previous content
        previewContainer.innerHTML = '';

        // Create a styled preview
        const previewDiv = document.createElement('div');
        previewDiv.style.cssText = `
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            background-color: white;
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-height: 400px;
            overflow-y: auto;
        `;

        // Add subject as header
        if (subject) {
            const subjectHeader = document.createElement('div');
            subjectHeader.style.cssText = `
                font-size: 18px;
                font-weight: bold;
                color: #333;
                border-bottom: 2px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 15px;
            `;
            subjectHeader.textContent = subject;
            previewDiv.appendChild(subjectHeader);
        }

        // Add email content
        const contentDiv = document.createElement('div');
        contentDiv.innerHTML = emailContent;
        previewDiv.appendChild(contentDiv);

        previewContainer.appendChild(previewDiv);
    }

    handleEditorCommand(command) {
        if (command === 'createLink') {
            const url = prompt('Ingresa la URL:');
            if (url) {
                document.execCommand(command, false, url);
            }
        } else if (command === 'insertImage') {
            const url = prompt('Ingresa la URL de la imagen:');
            if (url) {
                document.execCommand(command, false, url);
            }
        } else {
            document.execCommand(command, false, null);
        }

        document.getElementById('email-editor').focus();
    }

    previewEmail() {
        const content = document.getElementById('email-editor').value;
        const subject = document.getElementById('campaign-subject').value || 'Vista Previa del Email';

        const previewWindow = window.open('', '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        previewWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Vista Previa del Email</title>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        padding: 20px; 
                        margin: 0;
                        background-color: #f5f5f5;
                    }
                    .email-container {
                        max-width: 600px;
                        margin: 0 auto;
                        background-color: white;
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    }
                    .email-header {
                        border-bottom: 2px solid #eee;
                        padding-bottom: 15px;
                        margin-bottom: 20px;
                    }
                    .email-subject {
                        font-size: 18px;
                        font-weight: bold;
                        color: #333;
                        margin: 0;
                    }
                    .email-content {
                        line-height: 1.6;
                        color: #333;
                    }
                    .email-content img {
                        max-width: 100%;
                        height: auto;
                    }
                    .email-content a {
                        color: #6366f1;
                        text-decoration: none;
                    }
                    .email-content a:hover {
                        text-decoration: underline;
                    }
                </style>
            </head>
            <body>
                <div class="email-container">
                    <div class="email-header">
                        <h1 class="email-subject">${subject}</h1>
                    </div>
                    <div class="email-content">
                        ${content}
                    </div>
                </div>
            </body>
            </html>
        `);
        previewWindow.document.close();
    }

    async sendTestEmail() {
        const testEmail = document.getElementById('test-email-address').value;
        if (!testEmail) {
            this.showToast('error', 'Email requerido', 'Por favor ingresa un email para la prueba.');
            return;
        }

        const testData = {
            sender_id: document.getElementById('campaign-sender').value,
            subject: document.getElementById('campaign-subject').value,
            html_content: document.getElementById('email-editor').innerHTML,
            test_email: testEmail
        };

        try {
            await this.apiRequest('send-test', 'POST', testData);
            this.closeModal('test-email-modal');
            this.showToast('success', 'Prueba enviada', `Email de prueba enviado a ${testEmail}`);
        } catch (error) {
            // Error already handled in apiRequest
        }
    }

    async deleteSender(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este remitente?')) {
            try {
                await this.apiRequest(`senders/${id}`, 'DELETE');
                this.loadSenders();
                this.showToast('success', 'Remitente eliminado', 'El remitente se ha eliminado correctamente.');
            } catch (error) {
                // Error already handled in apiRequest
            }
        }
    }

    editSender(id) {
        // This would open the sender modal with the sender data pre-filled
        this.showToast('info', 'Próximamente', 'La función de edición estará disponible pronto.');
    }


    showToast(type, title, message) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${icons[type]}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close">
                <i class="fas fa-times"></i>
            </button>
        `;

        // Add close functionality
        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.remove();
        });

        toastContainer.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 5000);
    }

    ///gestion de contactos
    loadContacts() {
        this.filterContacts();
    }
    async loadContacts(page = 1, search = '') {
        this.currentPage = page;
        this.currentSearch = search;
        const tableBody = document.getElementById('contacts-tbody');
        if (!tableBody) return;
        tableBody.innerHTML = '<tr><td colspan="4">Cargando contactos...</td></tr>';
        try {
            const response = await this.apiRequest(`contacts?page=${page}&search=${encodeURIComponent(search)}`, 'GET');
            const { total, limit, data: contacts } = response;
            this.contacts = contacts;
            if (contacts.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" class="empty-state">No se encontraron contactos.</td></tr>';
            } else {
                tableBody.innerHTML = contacts.map(contact => {
                    const contactLists = this.getContactLists(contact.id);
                    const listsHTML = contactLists.length > 0
                        ? contactLists.map(list => `<span class="list-tag">${this.escapeHTML(list.name)}</span>`).join('')
                        : '<span style="color: var(--text-secondary); font-size: 0.75rem;">Sin listas</span>';

                    return `
            <tr>
                <td><input type="checkbox" class="contact-checkbox" value="${contact.id}"></td>
                <td>${this.escapeHTML(contact.name || '-')}</td>
                <td>${this.escapeHTML(contact.email || '-')}</td>
                <td>${this.escapeHTML(contact.status)}</td>
                <td>
                    <div class="contact-lists">
                        ${listsHTML}
                    </div>
                </td>
                <td>${contact.created_at ? new Date(contact.created_at).toLocaleDateString() : ''}</td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="emailApp.editContact(${contact.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="emailApp.deleteContact(${contact.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
                }).join('');
            }
            this.renderPagination(total, limit, page);
        } catch (error) {
            console.error('Error al cargar contactos:', error);
            tableBody.innerHTML = '<tr><td colspan="4" class="empty-state">Error al cargar los contactos.</td></tr>';
        }
    }
    /**
        * Escapa caracteres HTML para prevenir ataques XSS.
        * Esta función es crucial para la seguridad al renderizar datos del usuario.
        * @param {string} str - La cadena de texto a escapar.
        * @returns {string} - La cadena de texto segura.
        */
    escapeHTML(str) {
        if (typeof str !== 'string') return '';
        const p = document.createElement('p');
        p.appendChild(document.createTextNode(str));
        return p.innerHTML;
    }

    /**
     * Dibuja los botones de la paginación
     */
    renderPagination(total, limit, currentPage) {
        const paginationControls = document.getElementById('pagination-controls');
        const totalPages = Math.ceil(total / limit);
        if (!paginationControls) return;
        paginationControls.innerHTML = '';

        if (totalPages <= 1) return;

        // Botón "Anterior"
        paginationControls.innerHTML += `
            <button onclick="emailApp.loadContacts(${currentPage - 1}, emailApp.currentSearch)" ${currentPage === 1 ? 'disabled' : ''}>
                &laquo; Anterior
            </button>`;

        // Indicador de página
        paginationControls.innerHTML += `<span>Página ${currentPage} de ${totalPages}</span>`;

        // Botón "Siguiente"
        paginationControls.innerHTML += `
            <button onclick="emailApp.loadContacts(${currentPage + 1}, emailApp.currentSearch)" ${currentPage === totalPages ? 'disabled' : ''}>
                Siguiente &raquo;
            </button>`;
    }

    loadContactLists() {
        // Update lists tabs
        const listsTabs = document.getElementById('lists-tabs');
        const allContactsCount = this.contacts.length;

        let tabsHTML = `
            <button class="list-tab ${this.currentListId === 'all' ? 'active' : ''}" data-list="all">
                <i class="fas fa-users"></i>
                Todos los Contactos (<span id="all-contacts-count">${allContactsCount}</span>)
            </button>
        `;

        this.contactLists.forEach(list => {
            const memberCount = this.contactListMembers.filter(m => m.list_id === list.id).length;
            tabsHTML += `
                <button class="list-tab ${this.currentListId === list.id ? 'active' : ''}" data-list="${list.id}">
                    <i class="fas fa-list"></i>
                    ${list.name} (${memberCount})
                </button>
            `;
        });

        listsTabs.innerHTML = tabsHTML;

        // Add event listeners to tabs
        document.querySelectorAll('.list-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.currentListId = e.currentTarget.dataset.list;
                this.loadContactLists();
                this.filterContacts();
            });
        });

        // Update filter dropdown
        const filterList = document.getElementById('filter-list');
        filterList.innerHTML = '<option value="">Todas las listas</option>';
        this.contactLists.forEach(list => {
            filterList.innerHTML += `<option value="${list.id}">${list.name}</option>`;
        });

        // Update bulk action dropdown
        const bulkListSelect = document.getElementById('bulk-list-select');
        bulkListSelect.innerHTML = '<option value="">Agregar a lista...</option>';
        this.contactLists.forEach(list => {
            bulkListSelect.innerHTML += `<option value="${list.id}">${list.name}</option>`;
        });

        // Update import dropdown
        const importListSelect = document.getElementById('import-list-select');
        importListSelect.innerHTML = '<option value="">No agregar a ninguna lista</option>';
        this.contactLists.forEach(list => {
            importListSelect.innerHTML += `<option value="${list.id}">${list.name}</option>`;
        });

        // Update contact form checkboxes
        // this.updateContactListsCheckboxes();

        // Update lists modal
        // this.updateListsModal();
    }
    updateContactListsCheckboxes() {
        const container = document.getElementById('contact-lists-checkboxes');
        if (!container) return;

        if (this.contactLists.length === 0) {
            container.innerHTML = '<p class="empty-state">No hay listas disponibles</p>';
            return;
        }

        container.innerHTML = this.contactLists.map(list => `
            <div class="list-checkbox">
                <input type="checkbox" id="list-${list.id}" value="${list.id}">
                <label for="list-${list.id}">${list.name}</label>
            </div>
        `).join('');
    }

    updateListsModal() {
        const container = document.getElementById('lists-container');

        if (this.contactLists.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-list"></i>
                    <p>No hay listas creadas</p>
                </div>
            `;
            return;
        }

        container.innerHTML = this.contactLists.map(list => {
            const memberCount = this.contactListMembers.filter(m => m.list_id === list.id).length;
            return `
                <div class="list-item">
                    <div class="list-info">
                        <h4>${list.name}</h4>
                        <p>${list.description || 'Sin descripción'}</p>
                        <small>${memberCount} contactos • Creada el ${new Date(list.created_at).toLocaleDateString()}</small>
                    </div>
                    <div class="list-actions">
                        <button class="btn btn-sm btn-outline" onclick="emailApp.editList(${list.id})">
                            <i class="fas fa-edit"></i>
                            Editar
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="emailApp.deleteList(${list.id})">
                            <i class="fas fa-trash"></i>
                            Eliminar
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    handleListSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const list = Object.fromEntries(formData.entries());
        list.id = Date.now();
        list.created_at = new Date().toISOString();
        list.is_active = true;

        this.contactLists.push(list);
        localStorage.setItem('contactLists', JSON.stringify(this.contactLists));

        this.loadContactLists();
        this.closeModal('list-form-modal');
        this.showToast('success', '¡Lista creada!', 'La lista se ha creado correctamente.');
    }

    filterContacts() {
        const searchTerm = document.getElementById('search-contacts').value.toLowerCase();
        const statusFilter = document.getElementById('filter-status').value;
        const listFilter = document.getElementById('filter-list').value;

        let filteredContacts = [...this.contacts];

        // Filter by current tab (list)
        if (this.currentListId !== 'all') {
            const listContactIds = this.contactListMembers
                .filter(m => m.list_id == this.currentListId)
                .map(m => m.contact_id);
            filteredContacts = filteredContacts.filter(contact => listContactIds.includes(contact.id));
        }

        // Apply search filter
        if (searchTerm) {
            filteredContacts = filteredContacts.filter(contact =>
                contact.name.toLowerCase().includes(searchTerm) ||
                contact.email.toLowerCase().includes(searchTerm)
            );
        }

        // Apply status filter
        if (statusFilter) {
            filteredContacts = filteredContacts.filter(contact => contact.status === statusFilter);
        }

        // Apply list filter (additional filter)
        if (listFilter) {
            const listContactIds = this.contactListMembers
                .filter(m => m.list_id == listFilter)
                .map(m => m.contact_id);
            filteredContacts = filteredContacts.filter(contact => listContactIds.includes(contact.id));
        }

        this.displayContacts(filteredContacts);
    }

    displayContacts(contacts) {
        const tbody = document.getElementById('contacts-tbody');

        if (contacts.length === 0) {
            tbody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="7" class="empty-state">
                        <i class="fas fa-search"></i>
                        <p>No se encontraron contactos</p>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = contacts.map(contact => {
            const contactLists = this.getContactLists(contact.id);
            const listsHTML = contactLists.length > 0
                ? contactLists.map(list => `<span class="list-tag">${list.name}</span>`).join('')
                : '<span style="color: var(--text-secondary); font-size: 0.75rem;">Sin listas</span>';

            return `
                <tr>
                    <td><input type="checkbox" class="contact-checkbox" value="${contact.id}"></td>
                    <td>${contact.name}</td>
                    <td>${contact.email}</td>
                    <td>
                        <span class="status-badge status-${contact.status}">
                            ${contact.status === 'active' ? 'Activo' : 'Inactivo'}
                        </span>
                    </td>
                    <td>
                        <div class="contact-lists">
                            ${listsHTML}
                        </div>
                    </td>
                    <td>${new Date(contact.created_at).toLocaleDateString()}</td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="emailApp.editContact(${contact.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="emailApp.deleteContact(${contact.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        // Add event listeners to checkboxes
        document.querySelectorAll('.contact-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateBulkActions();
            });
        });
    }

    getContactLists(contactId) {
        const listIds = this.contactListMembers
            .filter(m => m.contact_id === contactId)
            .map(m => m.list_id);

        return this.contactLists.filter(list => listIds.includes(list.id));
    }

    updateBulkActions() {
        const selectedCheckboxes = document.querySelectorAll('.contact-checkbox:checked');
        const count = selectedCheckboxes.length;
        const bulkActions = document.getElementById('bulk-actions');
        const selectedCount = document.getElementById('selected-count');

        if (count > 0) {
            bulkActions.style.display = 'block';
            selectedCount.textContent = `${count} contactos seleccionados`;
        } else {
            bulkActions.style.display = 'none';
        }

        // Update select all checkbox
        const selectAll = document.getElementById('select-all-contacts');
        const totalCheckboxes = document.querySelectorAll('.contact-checkbox').length;
        selectAll.checked = count > 0 && count === totalCheckboxes;
        selectAll.indeterminate = count > 0 && count < totalCheckboxes;
    }

    toggleSelectAll(checked) {
        document.querySelectorAll('.contact-checkbox').forEach(checkbox => {
            checkbox.checked = checked;
        });
        this.updateBulkActions();
    }

    bulkAddToList() {
        const listId = document.getElementById('bulk-list-select').value;
        if (!listId) {
            this.showToast('warning', 'Selecciona una lista', 'Por favor selecciona una lista.');
            return;
        }

        const selectedContacts = Array.from(document.querySelectorAll('.contact-checkbox:checked'))
            .map(cb => parseInt(cb.value));

        let added = 0;
        selectedContacts.forEach(contactId => {
            // Check if already in list
            const exists = this.contactListMembers.some(m =>
                m.contact_id === contactId && m.list_id == listId
            );

            if (!exists) {
                this.contactListMembers.push({
                    id: Date.now() + Math.random(),
                    contact_id: contactId,
                    list_id: parseInt(listId),
                    added_at: new Date().toISOString()
                });
                added++;
            }
        });

        localStorage.setItem('contactListMembers', JSON.stringify(this.contactListMembers));
        this.loadContactLists();
        this.filterContacts();

        this.showToast('success', 'Contactos agregados', `${added} contactos agregados a la lista.`);
    }

    bulkRemoveFromList() {
        if (this.currentListId === 'all') {
            this.showToast('warning', 'Selecciona una lista', 'Primero selecciona una lista específica.');
            return;
        }

        const selectedContacts = Array.from(document.querySelectorAll('.contact-checkbox:checked'))
            .map(cb => parseInt(cb.value));

        this.contactListMembers = this.contactListMembers.filter(m =>
            !(selectedContacts.includes(m.contact_id) && m.list_id == this.currentListId)
        );

        localStorage.setItem('contactListMembers', JSON.stringify(this.contactListMembers));
        this.loadContactLists();
        this.filterContacts();

        this.showToast('success', 'Contactos removidos', 'Contactos removidos de la lista.');
    }

    bulkDeleteContacts() {
        if (!confirm('¿Estás seguro de que quieres eliminar los contactos seleccionados?')) {
            return;
        }

        const selectedContacts = Array.from(document.querySelectorAll('.contact-checkbox:checked'))
            .map(cb => parseInt(cb.value));

        this.contacts = this.contacts.filter(contact => !selectedContacts.includes(contact.id));
        this.contactListMembers = this.contactListMembers.filter(m => !selectedContacts.includes(m.contact_id));

        localStorage.setItem('contacts', JSON.stringify(this.contacts));
        localStorage.setItem('contactListMembers', JSON.stringify(this.contactListMembers));

        this.loadContactLists();
        this.filterContacts();
        this.updateStats();

        this.showToast('success', 'Contactos eliminados', `${selectedContacts.length} contactos eliminados.`);
    }

    // Import functionality
    handleFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const csv = e.target.result;
                this.csvData = this.parseCSV(csv);

                if (this.csvData.length === 0) {
                    this.showToast('error', 'Archivo vacío', 'El archivo CSV está vacío o no tiene formato válido.');
                    return;
                }

                // Validate required columns
                const headers = Object.keys(this.csvData[0]);
                if (!headers.includes('name') || !headers.includes('email')) {
                    this.showToast('error', 'Columnas faltantes', 'El archivo debe contener las columnas "name" y "email".');
                    return;
                }

                document.getElementById('import-next-step').disabled = false;
            } catch (error) {
                this.showToast('error', 'Error de archivo', 'No se pudo leer el archivo CSV.');
            }
        };
        reader.readAsText(file);
    }

    parseCSV(csv) {
        const lines = csv.split('\n').filter(line => line.trim());
        if (lines.length < 2) return [];

        const headers = lines[0].split(',').map(h => h.trim());
        const data = [];

        for (let i = 1; i < lines.length; i++) {
            const values = lines[i].split(',').map(v => v.trim());
            if (values.length >= headers.length) {
                const row = {};
                headers.forEach((header, index) => {
                    row[header] = values[index] || '';
                });
                data.push(row);
            }
        }

        return data;
    }

    nextImportStep() {
        if (this.currentImportStep === 1 && this.csvData) {
            this.currentImportStep = 2;
            this.updateImportStep();
            this.showImportPreview();
        }
    }

    prevImportStep() {
        if (this.currentImportStep > 1) {
            this.currentImportStep = 1;
            this.updateImportStep();
        }
    }

    updateImportStep() {
        // Update step indicators
        document.querySelectorAll('.import-steps .step').forEach(step => step.classList.remove('active'));
        document.querySelector(`[data-step="${this.currentImportStep}"]`).classList.add('active');

        // Update step content
        document.querySelectorAll('.import-step-content').forEach(content => content.classList.remove('active'));
        document.getElementById(`import-step-${this.currentImportStep}`).classList.add('active');

        // Update buttons
        document.getElementById('import-prev-step').style.display = this.currentImportStep > 1 ? 'inline-flex' : 'none';
        document.getElementById('import-next-step').style.display = this.currentImportStep < 2 ? 'inline-flex' : 'none';
        document.getElementById('import-contacts').style.display = this.currentImportStep === 2 ? 'inline-flex' : 'none';
    }

    showImportPreview() {
        const preview = document.getElementById('import-preview');
        if (!this.csvData || this.csvData.length === 0) return;

        const previewData = this.csvData.slice(0, 5); // Show first 5 rows
        const headers = Object.keys(this.csvData[0]);

        preview.innerHTML = `
            <h4>Vista previa (primeras 5 filas de ${this.csvData.length} total):</h4>
            <table class="import-preview-table">
                <thead>
                    <tr>
                        ${headers.map(h => `<th>${h}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>
                    ${previewData.map(row => `
                        <tr>
                            ${headers.map(h => `<td>${row[h] || ''}</td>`).join('')}
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    importContacts() {
        if (!this.csvData) return;

        const selectedListId = document.getElementById('import-list-select').value;
        const createNewList = document.getElementById('create-new-list-checkbox').checked;
        const newListName = document.getElementById('new-list-name').value;

        let targetListId = selectedListId;

        // Create new list if requested
        if (createNewList && newListName) {
            const newList = {
                id: Date.now(),
                name: newListName,
                description: `Lista creada durante importación de ${this.csvData.length} contactos`,
                created_at: new Date().toISOString(),
                is_active: true
            };

            this.contactLists.push(newList);
            localStorage.setItem('contactLists', JSON.stringify(this.contactLists));
            targetListId = newList.id;
        }

        let imported = 0;
        let skipped = 0;
        const errors = [];

        this.csvData.forEach((row, index) => {
            const email = row.email?.trim();
            const name = row.name?.trim();

            if (!email || !name) {
                errors.push(`Fila ${index + 2}: Email o nombre faltante`);
                return;
            }

            // Check if email already exists
            if (this.contacts.some(c => c.email === email)) {
                skipped++;
                return;
            }

            // Create contact
            const contact = {
                id: Date.now() + Math.random(),
                name: name,
                email: email,
                status: row.status || 'active',
                created_at: new Date().toISOString()
            };

            this.contacts.push(contact);

            // Add to list if specified
            if (targetListId) {
                this.contactListMembers.push({
                    id: Date.now() + Math.random(),
                    contact_id: contact.id,
                    list_id: parseInt(targetListId),
                    added_at: new Date().toISOString()
                });
            }

            imported++;
        });

        // Save data
        localStorage.setItem('contacts', JSON.stringify(this.contacts));
        localStorage.setItem('contactListMembers', JSON.stringify(this.contactListMembers));

        // Refresh UI
        this.loadContacts();
        this.loadContactLists();
        this.updateStats();
        this.closeModal('import-modal');

        // Show results
        let message = `${imported} contactos importados correctamente.`;
        if (skipped > 0) message += ` ${skipped} emails duplicados omitidos.`;
        if (errors.length > 0) message += ` ${errors.length} errores encontrados.`;

        this.showToast('success', 'Importación completada', message);

        // Reset import state
        this.currentImportStep = 1;
        this.csvData = null;
        document.getElementById('csv-file').value = '';
        this.updateImportStep();
    }

    deleteList(id) {
        if (confirm('¿Estás seguro de que quieres eliminar esta lista? Los contactos no se eliminarán.')) {
            this.contactLists = this.contactLists.filter(l => l.id !== id);
            this.contactListMembers = this.contactListMembers.filter(m => m.list_id !== id);

            localStorage.setItem('contactLists', JSON.stringify(this.contactLists));
            localStorage.setItem('contactListMembers', JSON.stringify(this.contactListMembers));

            // Reset to "all" if current list was deleted
            if (this.currentListId == id) {
                this.currentListId = 'all';
            }

            this.loadContactLists();
            this.filterContacts();
            this.showToast('success', 'Lista eliminada', 'La lista se ha eliminado correctamente.');
        }
    }

    editList(id) {
        this.showToast('info', 'Próximamente', 'La función de edición estará disponible pronto.');
    }

    deleteSender(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este remitente?')) {
            this.senders = this.senders.filter(s => s.id !== id);
            localStorage.setItem('senders', JSON.stringify(this.senders));
            this.loadSenders();
            this.showToast('success', 'Remitente eliminado', 'El remitente se ha eliminado correctamente.');
        }
    }

    deleteContact(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este contacto?')) {
            this.contacts = this.contacts.filter(c => c.id !== id);
            localStorage.setItem('contacts', JSON.stringify(this.contacts));
            this.loadContacts();
            this.updateStats();
            this.showToast('success', 'Contacto eliminado', 'El contacto se ha eliminado correctamente.');
        }
    }

    editSender(id) {
        // This would open the sender modal with the sender data pre-filled
        this.showToast('info', 'Próximamente', 'La función de edición estará disponible pronto.');
    }

    editContact(id) {
        // This would open the contact modal with the contact data pre-filled
        this.showToast('info', 'Próximamente', 'La función de edición estará disponible pronto.');
    }

}

// Initialize the app
const emailApp = new EmailMarketingApp();

// --- EVENT LISTENERS ---
document.addEventListener('DOMContentLoaded', () => {
    // Carga inicial de datos
    emailApp.loadCampaigns();
    emailApp.loadContacts(); // Carga la primera página de contactos

    // Listener para el campo de búsqueda

});

