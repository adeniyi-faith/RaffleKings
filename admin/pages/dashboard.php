<div class="mb-8 flex flex-col md:flex-row md:items-end justify-between space-y-4 md:space-y-0">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Overview</h1>
        <p class="text-sm text-slate-500 mt-1">System activity, performance metrics, and financial snapshots.</p>
    </div>
    <div class="flex space-x-3">
        <button class="px-4 py-2 bg-white border border-slate-200 shadow-sm text-sm font-medium rounded-lg text-slate-700 hover:bg-slate-50 transition-colors inline-flex items-center">
            <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Export Report
        </button>
        <button class="px-4 py-2 bg-indigo-600 shadow-sm shadow-indigo-200 text-sm font-medium rounded-lg text-white hover:bg-indigo-700 transition-colors">
            New Campaign
        </button>
    </div>
</div>

<!-- Stat Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Card 1 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 card-hover relative overflow-hidden">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-full opacity-50"></div>
        <div class="flex justify-between items-start relative z-10">
            <div>
                <p class="text-sm font-medium text-slate-500">Total Revenue</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 tracking-tight">₦2.4M</p>
            </div>
            <div class="p-2 bg-indigo-50 rounded-lg text-indigo-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
        <div class="mt-4 flex items-center text-sm">
            <span class="text-emerald-600 font-medium flex items-center bg-emerald-50 px-1.5 py-0.5 rounded text-xs mr-2">
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                12.5%
            </span>
            <span class="text-slate-400">vs last month</span>
        </div>
    </div>

    <!-- Card 2 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 card-hover relative overflow-hidden">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-full opacity-50"></div>
        <div class="flex justify-between items-start relative z-10">
            <div>
                <p class="text-sm font-medium text-slate-500">Active Users</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 tracking-tight">12,450</p>
            </div>
            <div class="p-2 bg-emerald-50 rounded-lg text-emerald-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
        </div>
        <div class="mt-4 flex items-center text-sm">
            <span class="text-emerald-600 font-medium flex items-center bg-emerald-50 px-1.5 py-0.5 rounded text-xs mr-2">
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                4.2%
            </span>
            <span class="text-slate-400">vs last month</span>
        </div>
    </div>

    <!-- Card 3 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 card-hover relative overflow-hidden">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-gradient-to-br from-amber-50 to-amber-100 rounded-full opacity-50"></div>
        <div class="flex justify-between items-start relative z-10">
            <div>
                <p class="text-sm font-medium text-slate-500">Pending Actions</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 tracking-tight">38</p>
            </div>
            <div class="p-2 bg-amber-50 rounded-lg text-amber-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            </div>
        </div>
        <div class="mt-4 text-sm text-amber-600 font-medium">
            Requires attention
        </div>
    </div>

    <!-- Card 4 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 card-hover relative overflow-hidden">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-gradient-to-br from-rose-50 to-rose-100 rounded-full opacity-50"></div>
        <div class="flex justify-between items-start relative z-10">
            <div>
                <p class="text-sm font-medium text-slate-500">Live Raffles</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 tracking-tight">24</p>
            </div>
            <div class="p-2 bg-rose-50 rounded-lg text-rose-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"></path></svg>
            </div>
        </div>
        <div class="mt-4 flex items-center text-sm">
            <span class="text-slate-500 font-medium mr-2">82%</span>
            <div class="w-full bg-slate-100 rounded-full h-1.5 mr-2">
                <div class="bg-rose-500 h-1.5 rounded-full" style="width: 82%"></div>
            </div>
            <span class="text-slate-400">Sold</span>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Main Chart Area -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 lg:col-span-2 flex flex-col">
        <div class="px-6 py-5 border-b border-slate-200 flex justify-between items-center">
            <h3 class="text-base font-semibold text-slate-900">Revenue & Engagement</h3>
            <select class="text-sm border-slate-200 rounded-md text-slate-500 focus:ring-indigo-500 focus:border-indigo-500 py-1.5 pl-3 pr-8">
                <option>Last 7 days</option>
                <option>Last 30 days</option>
                <option>This Year</option>
            </select>
        </div>
        <div class="p-6 flex-1 flex flex-col justify-center min-h-[300px]">
            <!-- Skeleton Chart -->
            <div class="animate-pulse flex items-end justify-between space-x-2 h-48 mt-4">
                <div class="w-1/12 bg-slate-200 rounded-t h-2/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-4/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-3/6"></div>
                <div class="w-1/12 bg-indigo-200 rounded-t h-5/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-4/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-6/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-3/6"></div>
                <div class="w-1/12 bg-slate-200 rounded-t h-4/6"></div>
            </div>
            <p class="text-center text-xs text-slate-400 mt-4">Chart visualization loading...</p>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-5 border-b border-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Recent Activity</h3>
        </div>
        <div class="p-6">
            <div class="flow-root">
                <ul class="-mb-8">
                    <li>
                        <div class="relative pb-8">
                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-slate-200" aria-hidden="true"></span>
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center ring-8 ring-white">
                                        <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Deposit approved for <span class="font-medium text-slate-900">Alex J.</span></p>
                                    </div>
                                    <div class="text-right text-xs text-slate-400 whitespace-nowrap">
                                        1h ago
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li>
                        <div class="relative pb-8">
                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-slate-200" aria-hidden="true"></span>
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center ring-8 ring-white">
                                        <svg class="h-4 w-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                    <div>
                                        <p class="text-sm text-slate-500">New user registration <span class="font-medium text-slate-900">Sarah M.</span></p>
                                    </div>
                                    <div class="text-right text-xs text-slate-400 whitespace-nowrap">
                                        3h ago
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li>
                        <div class="relative">
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="h-8 w-8 rounded-full bg-amber-100 flex items-center justify-center ring-8 ring-white">
                                        <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Withdrawal flagged for review</p>
                                    </div>
                                    <div class="text-right text-xs text-slate-400 whitespace-nowrap">
                                        5h ago
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="mt-6">
                <a href="?page=audit_logs" class="w-full flex justify-center items-center px-4 py-2 border border-slate-200 shadow-sm text-sm font-medium rounded-lg text-slate-700 bg-white hover:bg-slate-50 transition-colors">
                    View all activity
                </a>
            </div>
        </div>
    </div>
</div>