<?php

declare(strict_types=1);
use App\Saga\CheckoutSaga;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Shopwise\Kit\Messaging\MessageHandler;
use Shopwise\Kit\Messaging\Outbox;

arch()->preset()->php();
arch()->preset()->security();

arch('strict types and final classes in app code')
    ->expect('App')
    ->toUseStrictTypes()
    ->classes()->toBeFinal();

arch('controllers are thin invokables that delegate to actions')
    ->expect('App\Http\Controllers')
    ->toBeInvokable()
    ->not->toUse([DB::class, Outbox::class]);

arch('commands and events are only recorded by the saga')
    ->expect(Outbox::class)
    ->toOnlyBeUsedIn(['App\Saga']);

arch('message handlers only read replies and hand them to the saga')
    ->expect('App\Messaging\Handlers')
    ->toImplement(MessageHandler::class)
    ->not->toUse([Outbox::class, 'App\Clients']);

arch('other services are only called through the clients, and only from actions')
    ->expect([Http::class, Factory::class])
    ->toOnlyBeUsedIn(['App\Clients']);

arch('REST clients are used by actions only (never inside a reply transaction)')
    ->expect('App\Clients')
    ->toOnlyBeUsedIn(['App\Clients', 'App\Actions']);

arch('the saga row is only changed by the saga, created by StartCheckout and read elsewhere')
    ->expect(CheckoutSaga::class)
    ->toOnlyBeUsedIn(['App\Actions', 'App\Messaging\Handlers']);

arch('kafka is only touched through the kit')
    ->expect('App')
    ->not->toUse('Junges\Kafka');

arch('DTOs are immutable')
    ->expect('App\Data')
    ->toBeReadonly();
