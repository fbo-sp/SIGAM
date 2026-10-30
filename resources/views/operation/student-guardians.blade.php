@extends('layouts.app')
@section('title', 'Responsáveis — '.($student->person->social_name ?: $student->person->full_name))
@section('content')
@include('partials.back-to-dashboard')
@php($dojoStudents = app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'student.view'))
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><a href="{{ $dojoStudents ? route('operation.students', $dojo) : route('teacher.students') }}">Alunos</a><span>/</span><span>{{ $student->person->social_name ?: $student->person->full_name }}</span></nav>
<div class="page-heading"><div class="person-heading"><x-person-photo :person="$student->person" size="medium" /><div><span class="eyebrow">Matrícula {{ $student->registration_number }}</span><h1>{{ $student->person->social_name ?: $student->person->full_name }}</h1><p>{{ collect([$student->person->birth_date ? $student->person->birth_date->age.' anos' : null, $student->enrollments->pluck('classGroup.name')->filter()->unique()->sort()->implode(', ') ?: null])->filter()->implode(' · ') }}</p></div></div></div>
@if($errors->any() && ! old('form_key'))<div class="alert alert-warning">{{ $errors->first() }}</div>@endif
@include('sports.students.partials.guardians')
@endsection
