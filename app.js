class EmailMarketingApp {
    constructor() {
        this.currentSection = 'dashboard';
        this.currentStep = 1;
        this.apiUrl = 'https://marketing.dom0125.com/api.php';
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadDashboard();
        this.loadSenders();
       this.loadContacts();
       this.loadCampaigns();
      this.updateStats();
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
            this.filterContacts(e.target.value);
        });

        document.getElementById('filter-status').addEventListener('change', (e) => {
            this.filterContacts(document.getElementById('search-contacts').value, e.target.value);
        });

        // CSV Import
        document.getElementById('import-contacts-btn').addEventListener('click', () => {
            this.openModal('import-csv-modal');
        });

        document.getElementById('csv-import-form').addEventListener('submit', (e) => {
            this.handleCsvImport(e);
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

        // Preview email
      //  document.getElementById('preview-email').addEventListener('click', () => {
      //      this.previewEmail();
      //  });
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

            
  //const response = await fetch(`${this.apiUrl}?endpoint=${endpoint}`, options);
            const url = `${this.apiUrl.replace(/\/api\.php$/, '')}/api.php/${endpoint}`;
            const response = await fetch(url, options);
            
           const result = await response.json();
          // const text = await response.text();  // <- importante
        console.log('Contenido crudo del servidor:', result);
          // console.info(result);

          
           

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

        if (result.status==200) {
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

    async loadContacts() {
        try {
            const result = await this.apiRequest('contacts');
            const contacts = result.contacts || [];
            const tbody = document.getElementById('contacts-tbody');
            
            if (contacts.length === 0) {
                tbody.innerHTML = `
                    <tr class="empty-row">
                        <td colspan="6" class="empty-state">
                            <i class="fas fa-address-book"></i>
                            <p>No hay contactos registrados</p>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = contacts.map(contact => `
                    <tr>
                        <td><input type="checkbox" value="${contact.id}"></td>
                        <td>${contact.name}</td>
                        <td>${contact.email}</td>
                        <td>
                            <span class="status-badge status-${contact.status}">
                                ${contact.status === 'active' ? 'Activo' : 'Inactivo'}
                            </span>
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
                `).join('');
            }
        } catch (error) {
            document.getElementById('contacts-tbody').innerHTML = `
                <tr class="empty-row">
                    <td colspan="6" class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error al cargar contactos</p>
                    </td>
                </tr>
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
                        
                        <p><strong>Enviados:</strong> ${campaign.total_sent || 0} de ${campaign.total_recipients || 0}destinatarios</p>
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
            const activityFeed = document.getElementById('activity-feed');
            activityFeed.innerHTML = '<p class="empty-state">Actividad disponible pronto</p>';
            
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

    async filterContacts(searchTerm, statusFilter = '') {
        try {
            const params = new URLSearchParams();
            if (searchTerm) params.append('search', searchTerm);
            if (statusFilter) params.append('status', statusFilter);
            
            const result = await this.apiRequest(`contacts?${params.toString()}`);
            const contacts = result.contacts || [];
            const tbody = document.getElementById('contacts-tbody');
            
            if (contacts.length === 0) {
                tbody.innerHTML = `
                    <tr class="empty-row">
                        <td colspan="6" class="empty-state">
                            <i class="fas fa-search"></i>
                            <p>No se encontraron contactos</p>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = contacts.map(contact => `
                    <tr>
                        <td><input type="checkbox" value="${contact.id}"></td>
                        <td>${contact.name}</td>
                        <td>${contact.email}</td>
                        <td>
                            <span class="status-badge status-${contact.status}">
                                ${contact.status === 'active' ? 'Activo' : 'Inactivo'}
                            </span>
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
                `).join('');
            }
        } catch (error) {
            // Show error in table
        }
    }

    async openCampaignModal() {
        try {
            const senders = await this.apiRequest('senders');
            const contacts = await this.apiRequest('contacts');
            const activeContacts = contacts.contacts ? contacts.contacts.filter(c => c.status === 'active') : [];
            
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

    async deleteContact(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este contacto?')) {
            try {
                await this.apiRequest(`contacts/${id}`, 'DELETE');
                this.loadContacts();
                this.updateStats();
                this.showToast('success', 'Contacto eliminado', 'El contacto se ha eliminado correctamente.');
            } catch (error) {
                // Error already handled in apiRequest
            }
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
}

// Initialize the app
const emailApp = new EmailMarketingApp();

// Add some sample data for demonstration
if (localStorage.getItem('senders') === null) {
    const sampleSenders = [
        {
            id: 1,
            name: 'Marketing Team',
            email: 'marketing@miempresa.com',
            smtp_host: 'smtp.gmail.com',
            smtp_port: 587,
            smtp_username: 'marketing@miempresa.com',
            smtp_password: '****',
            created_at: new Date().toISOString()
        }
    ];
    localStorage.setItem('senders', JSON.stringify(sampleSenders));
}

if (localStorage.getItem('contacts') === null) {
    const sampleContacts = [
        {
            id: 1,
            name: 'Juan Pérez',
            email: 'juan@example.com',
            status: 'active',
            created_at: new Date(Date.now() - 86400000).toISOString()
        },
        {
            id: 2,
            name: 'María García',
            email: 'maria@example.com',
            status: 'active',
            created_at: new Date(Date.now() - 172800000).toISOString()
        },
        {
            id: 3,
            name: 'Carlos López',
            email: 'carlos@example.com',
            status: 'inactive',
            created_at: new Date(Date.now() - 259200000).toISOString()
        }
    ];
    localStorage.setItem('contacts', JSON.stringify(sampleContacts));
}

// Reload the app with sample data
//setTimeout(() => {
  //  emailApp.senders = JSON.parse(localStorage.getItem('senders') || '[]');
    //emailApp.contacts = JSON.parse(localStorage.getItem('contacts') || '[]');
    //emailApp.loadSenders();
    //emailApp.loadContacts();
    //emailApp.loadDashboard();
    //emailApp.updateStats();
//}, 100);