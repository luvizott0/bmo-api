@extends('layouts.admin')

@section('title', 'Login Administrativo - Home Manager')

@section('content')
<div class="min-h-[calc(100vh-16rem)] flex items-center justify-center">
    <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-xl shadow-slate-200/50 p-8 sm:p-10">
        <!-- Card Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 text-white font-extrabold text-2xl shadow-lg shadow-indigo-600/25 mb-4">
                HM
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">
                Acesso Administrativo
            </h1>
            <p class="text-sm text-slate-500 font-medium mt-1.5">
                Faça login com sua conta admin para cadastrar novos usuários.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Login Form -->
        <form method="POST" action="{{ route('admin.login') }}" class="space-y-5">
            @csrf

            <div>
                <label for="login" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                    Usuário ou E-mail
                </label>
                <div class="relative">
                    <input
                        type="text"
                        name="login"
                        id="login"
                        value="{{ old('login') }}"
                        required
                        autofocus
                        placeholder="admin ou admin@admin.com"
                        class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all shadow-xs"
                    >
                </div>
            </div>

            <div>
                <label for="password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                    Senha
                </label>
                <div class="relative">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        required
                        placeholder="••••••••"
                        class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all shadow-xs"
                    >
                </div>
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="remember" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                    <span class="text-xs font-semibold text-slate-600">Lembrar-me</span>
                </label>
            </div>

            <button
                type="submit"
                class="w-full inline-flex items-center justify-center px-4 py-3 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-[0.99] rounded-xl shadow-md shadow-indigo-600/25 transition-all"
            >
                Entrar no Painel
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-100 text-center">
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-100 text-xs text-slate-500 font-medium">
                <span>🛡️</span> Área protegida para administradores
            </div>
        </div>
    </div>
</div>
@endsection
