        <form @submit.prevent="saveProfile" class="space-y-6">

            <!-- Dynamic Message Box -->
            <div x-show="message" x-transition
                 :class="isError ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-100 dark:border-red-900' : 'bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border border-green-100 dark:border-green-900'"
                 class="p-3 rounded-xl text-sm font-medium text-center"
                 x-text="message" x-cloak>
            </div>

            <!-- Profile Picture -->
            <div class="flex flex-col items-center justify-center mb-6">
                <div class="relative group cursor-pointer active:scale-95 transition-transform" @click="$refs.fileInput.click()">
                    <div class="w-24 h-24 rounded-full p-1 border-2 border-dashed border-app-primary bg-blue-50 dark:bg-blue-900/20">
                        <img :src="form.avatar" class="w-full h-full rounded-full object-cover shadow-sm">
                    </div>
                    <div class="absolute bottom-0 right-0 bg-app-primary text-white p-2 rounded-full shadow-md border-2 border-white dark:border-dark-bg">
                        <i data-lucide="camera" class="w-4 h-4"></i>
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2">Tap to change photo</p>
                <input type="file" x-ref="fileInput" name="profile_image" class="hidden" accept="image/*" @change="previewImage">
            </div>

            <!-- Personal Info -->
            <div class="bg-white dark:bg-dark-card p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 space-y-5 transition-colors duration-200">
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 border-b border-gray-50 dark:border-gray-700 pb-2">Personal Details</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">First Name</label>
                        <input type="text" x-model="form.first_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Last Name</label>
                        <input type="text" x-model="form.last_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Display Name</label>
                    <input type="text" x-model="form.display_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Email Address</label>
                    <input type="email" x-model="form.email" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                </div>

                 <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Phone Number</label>
                    <input type="tel" x-model="form.phone" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors" placeholder="08012345678">
                </div>

                <!-- State Select -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">State of Residence</label>
                    <div class="relative">
                        <select x-model="form.state" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                            <option value="">Select State</option>
                            <option value="Abia">Abia</option>
                            <option value="Adamawa">Adamawa</option>
                            <option value="Akwa Ibom">Akwa Ibom</option>
                            <option value="Anambra">Anambra</option>
                            <option value="Bauchi">Bauchi</option>
                            <option value="Bayelsa">Bayelsa</option>
                            <option value="Benue">Benue</option>
                            <option value="Borno">Borno</option>
                            <option value="Cross River">Cross River</option>
                            <option value="Delta">Delta</option>
                            <option value="Ebonyi">Ebonyi</option>
                            <option value="Edo">Edo</option>
                            <option value="Ekiti">Ekiti</option>
                            <option value="Enugu">Enugu</option>
                            <option value="FCT">FCT - Abuja</option>
                            <option value="Gombe">Gombe</option>
                            <option value="Imo">Imo</option>
                            <option value="Jigawa">Jigawa</option>
                            <option value="Kaduna">Kaduna</option>
                            <option value="Kano">Kano</option>
                            <option value="Katsina">Katsina</option>
                            <option value="Kebbi">Kebbi</option>
                            <option value="Kogi">Kogi</option>
                            <option value="Kwara">Kwara</option>
                            <option value="Lagos">Lagos</option>
                            <option value="Nasarawa">Nasarawa</option>
                            <option value="Niger">Niger</option>
                            <option value="Ogun">Ogun</option>
                            <option value="Ondo">Ondo</option>
                            <option value="Osun">Osun</option>
                            <option value="Oyo">Oyo</option>
                            <option value="Plateau">Plateau</option>
                            <option value="Rivers">Rivers</option>
                            <option value="Sokoto">Sokoto</option>
                            <option value="Taraba">Taraba</option>
                            <option value="Yobe">Yobe</option>
                            <option value="Zamfara">Zamfara</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 dark:text-gray-400">
                            <i data-lucide="chevron-down" class="w-4 h-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password -->
            <div class="bg-white dark:bg-dark-card p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 space-y-4 transition-colors duration-200">
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 border-b border-gray-50 dark:border-gray-700 pb-2">Security</h2>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">New Password</label>
                    <input type="password" x-model="form.password" placeholder="Leave empty to keep current" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors placeholder-gray-400 dark:placeholder-gray-600">
                </div>
            </div>

            <!-- Action Button -->
            <button type="submit" :disabled="isSaving" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-4 rounded-xl font-bold shadow-lg shadow-gray-200 dark:shadow-none flex items-center justify-center gap-2 active:scale-[0.98] transition-all hover:bg-gray-800 dark:hover:bg-gray-100">
                <template x-if="isSaving">
                    <span class="animate-spin"><i data-lucide="loader-2" class="w-5 h-5"></i></span>
                </template>
                <span x-text="isSaving ? 'Saving...' : 'Save Changes'"></span>
                <template x-if="!isSaving">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                </template>
            </button>

            <!-- Extra bottom padding for safe scrolling past floating UI -->
            <div class="h-6"></div>
        </form>
