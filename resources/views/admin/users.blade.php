@extends('layouts.admin')

@section('title', 'Gestão de Usuários - Home Manager')

@section('content')
<div class="space-y-8">
    <!-- Page Header & Stats -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                Gestão de Usuários
            </h1>
            <p class="text-sm text-slate-500 font-medium mt-1">
                Cadastre membros para a plataforma. Eles receberão a senha padrão e poderão alterá-la depois.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="px-4 py-2 bg-white rounded-xl border border-slate-200 shadow-2xs">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block">Total Cadastrado</span>
                <span class="text-xl font-extrabold text-indigo-600">{{ $users->count() }}</span>
            </div>
            <div class="px-4 py-2 bg-white rounded-xl border border-slate-200 shadow-2xs">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block">Admins</span>
                <span class="text-xl font-extrabold text-slate-900">{{ $users->where('is_admin', true)->count() }}</span>
            </div>
        </div>
    </div>

    <!-- Main Grid: Form + Users Table -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <!-- New User Form (Left 4 cols on desktop) -->
        <div class="lg:col-span-4 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-7">
            <div class="flex items-center gap-2.5 mb-6">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Novo Usuário</h2>
                    <p class="text-xs text-slate-500">Preencha os dados do novo membro</p>
                </div>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-xl bg-red-50 border border-red-200 p-3.5 text-xs text-red-700">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                        Nome Completo
                    </label>
                    <input
                        type="text"
                        name="name"
                        id="name"
                        value="{{ old('name') }}"
                        required
                        placeholder="Ex: João da Silva"
                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all shadow-2xs"
                    >
                </div>

                <div>
                    <label for="email" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                        E-mail
                    </label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        value="{{ old('email') }}"
                        required
                        placeholder="Ex: joao@email.com"
                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all shadow-2xs"
                    >
                </div>

                <!-- Info Notice about default password -->
                <div class="rounded-xl bg-amber-50/80 border border-amber-200/80 p-3.5 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                    <div class="text-xs text-amber-900 font-medium leading-relaxed">
                        A senha inicial será <code class="px-1.5 py-0.5 rounded bg-amber-100 font-mono font-bold text-amber-950">password</code>. O usuário terá um workspace pessoal criado e poderá alterar a senha após o login.
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-[0.99] rounded-xl shadow-md shadow-indigo-600/20 transition-all cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Cadastrar Usuário
                </button>
            </form>
        </div>

        <!-- Users Table (Right 8 cols on desktop) -->
        <div class="lg:col-span-8 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Usuários no Sistema</h2>
                    <p class="text-xs text-slate-500">Membros cadastrados com acesso à aplicação</p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 text-slate-600">
                    {{ $users->count() }} {{ $users->count() === 1 ? 'usuário' : 'usuários' }}
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                            <th class="py-3 px-4">ID</th>
                            <th class="py-3 px-4">Nome</th>
                            <th class="py-3 px-4">E-mail</th>
                            <th class="py-3 px-4">Perfil</th>
                            <th class="py-3 px-4 text-center">Workspaces</th>
                            <th class="py-3 px-4 text-right">Cadastrado em</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        @forelse ($users as $user)
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="py-3.5 px-4 font-mono text-xs text-slate-400">#{{ $user->id }}</td>
                                <td class="py-3.5 px-4 font-semibold text-slate-900">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center justify-center border border-slate-200">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>
                                        <span>{{ $user->name }}</span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600 font-mono text-xs">{{ $user->email }}</td>
                                <td class="py-3.5 px-4">
                                    @if ($user->isAdmin())
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200/60">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                            </svg>
                                            Administrador
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                            Usuário
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-mono">
                                        {{ $user->workspaces_count }} {{ $user->workspaces_count === 1 ? 'espaço' : 'espaços' }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right text-xs text-slate-400 font-mono">
                                    {{ $user->created_at?->format('d/m/Y H:i') ?? '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-400 text-sm">
                                    Nenhum usuário cadastrado ainda.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
