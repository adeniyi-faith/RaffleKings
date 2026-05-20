<script>
    function editProfile() {
        return {
            form: {
                // Injected directly from our gracious server
                first_name: <?= json_encode($ssr_first_name) ?>,
                last_name: <?= json_encode($ssr_last_name) ?>,
                display_name: <?= json_encode($ssr_display_name) ?>,
                email: <?= json_encode($ssr_email) ?>,
                phone: <?= json_encode($ssr_phone) ?>,
                state: <?= json_encode($ssr_state) ?>,
                password: '',
                avatar: <?= json_encode($ssr_avatar) ?>
            },
            isSaving: false,
            message: '',
            isError: false,
            imageFile: null,

            initForm() {
                // Initialize icons gracefully
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                // Note: The fetchRemoteProfile() call has been banished!
                // The data is already here, perfectly composed.
            },

            previewImage(e) {
                const file = e.target.files[0];
                if (file) {
                    this.imageFile = file;
                    const reader = new FileReader();
                    reader.onload = (e) => { this.form.avatar = e.target.result; };
                    reader.readAsDataURL(file);
                }
            },

            async saveProfile() {
                this.isSaving = true;
                this.message = '';

                const formData = new FormData();
                // Add our Local Proxy action
                formData.append('action', 'update_profile');

                Object.keys(this.form).forEach(key => {
                    if(key !== 'avatar' && this.form[key]) formData.append(key, this.form[key]);
                });

                if (this.imageFile) formData.append('profile_image', this.imageFile);

                try {
                    // We dispatch the coach to our very own estate (window.location.href)
                    const res = await fetch(window.location.href.split('?')[0], {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if(res.ok && data.success) {
                        this.isError = false;
                        this.message = data.message;

                        // Update cache merely for the benefit of other legacy pages
                        this.updateCache({
                            ...this.form,
                            avatar: data.avatar || this.form.avatar
                        });

                        setTimeout(() => window.location.href = 'profile.php', 1000);
                    } else {
                        throw new Error(data.message || 'An unfortunate error occurred whilst updating.');
                    }
                } catch(e) {
                    this.isError = true;
                    this.message = e.message;
                } finally {
                    this.isSaving = false;
                }
            },

            updateCache(data) {
                if(data.first_name) localStorage.setItem('user_first_name', data.first_name);
                if(data.last_name) localStorage.setItem('user_last_name', data.last_name);
                if(data.display_name) {
                    localStorage.setItem('user_display_name', data.display_name);
                    localStorage.setItem('user_nicename', data.display_name);
                }
                if(data.email) localStorage.setItem('user_email', data.email);
                if(data.phone) localStorage.setItem('user_phone', data.phone);
                if(data.state) localStorage.setItem('user_state', data.state);
                if(data.avatar) localStorage.setItem('user_avatar_url', data.avatar);
            }
        }
    }
</script>
