@extends('layout')
@section('content')
<div class="page-head"><div><a class="text-link" href="/deals">← Agreements</a><h1>Create agreement</h1><p>Prepare a draft with client details, stock items and terms.</p></div></div>
<section class="card card-content"><h2>Agreement draft</h2>@include('workflow.agreement-form', ['formData' => [], 'action' => '/deals'])</section>
@endsection
