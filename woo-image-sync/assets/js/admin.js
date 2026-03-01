/**
 * WooCommerce Image Sync - Admin JavaScript
 */
(function ($) {
    'use strict';

    var WIS = {
        files: [],
        isProcessing: false,

        init: function () {
            this.bindTabs();
            this.bindUpload();
            this.bindServerSync();
            this.bindLog();
        },

        // ==================
        // Tab Navigation
        // ==================
        bindTabs: function () {
            $('.wis-tabs .nav-tab').on('click', function (e) {
                e.preventDefault();
                var tabId = $(this).data('tab');

                $('.wis-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.wis-tab-content').removeClass('active');
                $('#' + tabId).addClass('active');
            });
        },

        // ==================
        // File Upload
        // ==================
        bindUpload: function () {
            var self = this;
            var $dropZone = $('#wis-drop-zone');
            var $fileInput = $('#wis-file-input');

            // Click on drop zone to open file selector
            $dropZone.on('click', function (e) {
                if (e.target.tagName !== 'INPUT') {
                    $fileInput.trigger('click');
                }
            });

            // File input change
            $fileInput.on('change', function () {
                self.addFiles(this.files);
                this.value = '';
            });

            // Drag and drop
            $dropZone.on('dragover dragenter', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $dropZone.on('dragleave drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $dropZone.on('drop', function (e) {
                var dt = e.originalEvent.dataTransfer;
                if (dt && dt.files) {
                    self.addFiles(dt.files);
                }
            });

            // Upload button
            $('#wis-upload-btn').on('click', function () {
                self.startUpload();
            });

            // Clear button
            $('#wis-clear-btn').on('click', function () {
                self.clearFiles();
            });
        },

        addFiles: function (fileList) {
            var allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            for (var i = 0; i < fileList.length; i++) {
                var file = fileList[i];
                var ext = file.name.split('.').pop().toLowerCase();

                if (allowedExt.indexOf(ext) === -1) {
                    continue;
                }

                // Avoid duplicate filenames
                var exists = false;
                for (var j = 0; j < this.files.length; j++) {
                    if (this.files[j].name === file.name) {
                        exists = true;
                        break;
                    }
                }

                if (!exists) {
                    this.files.push(file);
                }
            }

            this.renderFileList();
        },

        renderFileList: function () {
            var $list = $('#wis-file-items');
            var $container = $('#wis-file-list');
            var $count = $('#wis-file-count');

            $list.empty();
            $count.text(this.files.length);

            if (this.files.length === 0) {
                $container.hide();
                return;
            }

            $container.show();

            for (var i = 0; i < this.files.length; i++) {
                var file = this.files[i];
                var size = this.formatSize(file.size);
                var nameWithoutExt = file.name.replace(/\.[^/.]+$/, '');

                var $item = $(
                    '<div class="wis-file-item" data-index="' + i + '">' +
                    '  <span class="wis-file-name">' +
                    '    <span class="dashicons dashicons-format-image"></span>' +
                    '    <strong>' + this.escapeHtml(file.name) + '</strong>' +
                    '    <em>(SKU: ' + this.escapeHtml(nameWithoutExt) + ')</em>' +
                    '  </span>' +
                    '  <span class="wis-file-size">' + size + '</span>' +
                    '  <button type="button" class="wis-file-remove" title="Eliminar">' +
                    '    <span class="dashicons dashicons-no-alt"></span>' +
                    '  </button>' +
                    '</div>'
                );

                $list.append($item);
            }

            // Bind remove buttons
            var self = this;
            $list.find('.wis-file-remove').on('click', function () {
                var idx = $(this).closest('.wis-file-item').data('index');
                self.files.splice(idx, 1);
                self.renderFileList();
            });
        },

        clearFiles: function () {
            this.files = [];
            this.renderFileList();
        },

        startUpload: function () {
            if (this.files.length === 0) {
                alert(wisAjax.strings.noFiles);
                return;
            }

            if (this.isProcessing) {
                return;
            }

            if (!confirm(wisAjax.strings.confirmSync)) {
                return;
            }

            this.isProcessing = true;
            this.showProgress();
            this.processQueue(0, {
                total: this.files.length,
                success: 0,
                skipped: 0,
                no_match: 0,
                error: 0
            });
        },

        processQueue: function (index, summary) {
            var self = this;

            if (index >= this.files.length) {
                this.isProcessing = false;
                this.showResults(summary);
                this.clearFiles();
                return;
            }

            var file = this.files[index];
            var progress = Math.round(((index + 1) / this.files.length) * 100);

            this.updateProgress(
                progress,
                wisAjax.strings.processing + ' ' + (index + 1) + ' ' + wisAjax.strings.of + ' ' + this.files.length + ': ' + file.name
            );

            var formData = new FormData();
            formData.append('action', 'wis_upload_images');
            formData.append('nonce', wisAjax.nonce);
            formData.append('image', file);

            $.ajax({
                url: wisAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success && response.data) {
                        var status = response.data.status;
                        if (summary.hasOwnProperty(status)) {
                            summary[status]++;
                        }
                        self.addProgressDetail(response.data.message, status);
                    } else {
                        summary.error++;
                        var msg = response.data && response.data.message
                            ? response.data.message
                            : wisAjax.strings.error;
                        self.addProgressDetail(msg, 'error');
                    }
                },
                error: function () {
                    summary.error++;
                    self.addProgressDetail(
                        wisAjax.strings.error + ': ' + file.name,
                        'error'
                    );
                },
                complete: function () {
                    self.processQueue(index + 1, summary);
                }
            });
        },

        // ==================
        // Server Sync
        // ==================
        bindServerSync: function () {
            var self = this;

            $('#wis-scan-btn').on('click', function () {
                self.scanFolder();
            });

            $('#wis-sync-server-btn').on('click', function () {
                self.syncServer();
            });
        },

        scanFolder: function () {
            var self = this;
            var $btn = $('#wis-scan-btn');
            var $syncBtn = $('#wis-sync-server-btn');
            var $results = $('#wis-scan-results');
            var $items = $('#wis-scan-items');

            $btn.prop('disabled', true).text(wisAjax.strings.syncing);

            $.post(wisAjax.ajaxUrl, {
                action: 'wis_scan_folder',
                nonce: wisAjax.nonce
            }, function (response) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-search"></span> Escanear Carpeta'
                );

                if (!response.success) {
                    alert(response.data.message);
                    return;
                }

                var images = response.data.images;
                $items.empty();

                if (images.length === 0) {
                    $items.html('<p>No se encontraron imagenes en la carpeta.</p>');
                    $results.show();
                    $syncBtn.prop('disabled', true);
                    return;
                }

                for (var i = 0; i < images.length; i++) {
                    var img = images[i];
                    var matchClass = 'match-none';
                    var matchText = 'Sin producto';

                    if (img.product_id > 0) {
                        if (img.has_image) {
                            matchClass = 'has-image';
                            matchText = img.product_name + ' (ya tiene imagen)';
                        } else {
                            matchClass = 'match-found';
                            matchText = img.product_name;
                        }
                    }

                    var $item = $(
                        '<div class="wis-scan-item">' +
                        '  <span class="wis-scan-file">' +
                        '    <span class="dashicons dashicons-format-image"></span>' +
                        '    <strong>' + self.escapeHtml(img.filename) + '</strong>' +
                        '    <span class="wis-file-size">(' + img.size_formatted + ')</span>' +
                        '  </span>' +
                        '  <span class="wis-scan-match ' + matchClass + '">' + self.escapeHtml(matchText) + '</span>' +
                        '</div>'
                    );

                    $items.append($item);
                }

                $results.show();
                $syncBtn.prop('disabled', false);
            });
        },

        syncServer: function () {
            if (this.isProcessing) {
                return;
            }

            if (!confirm(wisAjax.strings.confirmSync)) {
                return;
            }

            var self = this;
            this.isProcessing = true;
            this.showProgress();
            this.updateProgress(0, wisAjax.strings.syncing);

            // First scan to get the list of images, then process one by one
            $.post(wisAjax.ajaxUrl, {
                action: 'wis_scan_folder',
                nonce: wisAjax.nonce
            }, function (response) {
                if (!response.success) {
                    self.isProcessing = false;
                    self.updateProgress(100, response.data.message);
                    return;
                }

                var images = response.data.images;
                if (!images || images.length === 0) {
                    self.isProcessing = false;
                    self.updateProgress(100, 'No se encontraron imagenes en la carpeta.');
                    return;
                }

                // Store server images and process one by one to avoid timeout
                self.serverImages = images;
                self.processServerQueue(0, {
                    total: images.length,
                    success: 0,
                    skipped: 0,
                    no_match: 0,
                    error: 0
                });
            }).fail(function () {
                self.isProcessing = false;
                self.updateProgress(100, wisAjax.strings.error);
            });
        },

        processServerQueue: function (index, summary) {
            var self = this;

            if (index >= this.serverImages.length) {
                this.isProcessing = false;
                this.showResults(summary);
                return;
            }

            var img = this.serverImages[index];
            var progress = Math.round(((index + 1) / this.serverImages.length) * 100);

            this.updateProgress(
                progress,
                wisAjax.strings.processing + ' ' + (index + 1) + ' ' + wisAjax.strings.of + ' ' + this.serverImages.length + ': ' + img.filename
            );

            $.post(wisAjax.ajaxUrl, {
                action: 'wis_process_single',
                nonce: wisAjax.nonce,
                file_path: img.path,
                filename: img.filename
            }, function (response) {
                if (response.success && response.data) {
                    var status = response.data.status;
                    if (summary.hasOwnProperty(status)) {
                        summary[status]++;
                    }
                    self.addProgressDetail(response.data.message, status);
                } else {
                    summary.error++;
                    var msg = response.data && response.data.message
                        ? response.data.message
                        : wisAjax.strings.error;
                    self.addProgressDetail(msg, 'error');
                }
            }).fail(function () {
                summary.error++;
                self.addProgressDetail(
                    wisAjax.strings.error + ': ' + img.filename,
                    'error'
                );
            }).always(function () {
                self.processServerQueue(index + 1, summary);
            });
        },

        // ==================
        // Log
        // ==================
        bindLog: function () {
            $('#wis-clear-log-btn').on('click', function () {
                // Clear is handled via page reload after clearing option
                $.post(wisAjax.ajaxUrl, {
                    action: 'wis_clear_log',
                    nonce: wisAjax.nonce
                }, function () {
                    $('#wis-log-container').html(
                        '<p class="wis-log-empty">No hay registros de sincronizacion aun.</p>'
                    );
                    $('#wis-clear-log-btn').hide();
                });
            });
        },

        // ==================
        // Progress & Results
        // ==================
        showProgress: function () {
            var $progress = $('#wis-progress');
            var $results = $('#wis-results');

            $results.hide();
            $('#wis-progress-details').empty();
            $('#wis-progress-fill').css('width', '0%');
            $('#wis-progress-text').text('0%');
            $progress.show();
        },

        updateProgress: function (percent, text) {
            $('#wis-progress-fill').css('width', percent + '%');
            $('#wis-progress-text').text(percent + '%');
            if (text) {
                $('#wis-progress-title').text(text);
            }
        },

        addProgressDetail: function (message, status) {
            var $details = $('#wis-progress-details');
            var $item = $(
                '<div class="wis-progress-detail-item status-' + status + '">' +
                this.escapeHtml(message) +
                '</div>'
            );
            $details.append($item);
            $details.scrollTop($details[0].scrollHeight);
        },

        showResults: function (summary) {
            var $results = $('#wis-results');

            $('#wis-result-success').text(summary.success || 0);
            $('#wis-result-skipped').text(summary.skipped || 0);
            $('#wis-result-nomatch').text(summary.no_match || 0);
            $('#wis-result-errors').text(summary.error || 0);

            $results.show();
        },

        // ==================
        // Utilities
        // ==================
        formatSize: function (bytes) {
            if (bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        escapeHtml: function (str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(document).ready(function () {
        WIS.init();
    });

})(jQuery);
