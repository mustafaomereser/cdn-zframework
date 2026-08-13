@extends('cdn.main')
@section('title')<?= _l('cdn.projects.add') ?>@endsection
@section('lede')<?= _l('cdn.projects.add-lede') ?>@endsection

@section('body')
<div class="card" style="max-width: 560px">
    <div class="card-body">
        <form action="{{ route('cdn-admin.projects.create.save') }}" method="POST">
            <?= csrf() ?>

            <label class="form-label">{{ _l('cdn.projects.name') }}</label>
            <input name="name" class="form-control" required autofocus placeholder="{{ _l('cdn.settings.add-holder') }}">

            <?php # What the url will be, said before it is created rather than
                  # after: the slug cannot be changed once it is in addresses. ?>
            <div class="form-text">
                {{ _l('cdn.projects.url-note') }}
                <span class="mono notranslate" translate="no"><?= $prefix ?>/<?= $account ?>-…/</span>
            </div>

            <div class="alert alert-light border mt-3 small mb-3">
                {{ _l('cdn.projects.quota-note') }}
            </div>

            <button class="btn btn-primary">{{ _l('cdn.common.create') }}</button>
            <a href="{{ route('cdn-admin.projects') }}" class="btn btn-link">{{ _l('cdn.common.cancel') }}</a>
        </form>
    </div>
</div>
@endsection
