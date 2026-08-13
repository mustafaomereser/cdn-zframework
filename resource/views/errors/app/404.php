<?php

use zFramework\Core\Facades\Auth;

if (!Auth::check()) redirect();
?>
@extends('cdn.main')

@section('body')
<div class="text-center my-5">
    <div>
        404 Not Found Page
    </div>
    <div>
        <?= $message ?>
    </div>
</div>
@endsection