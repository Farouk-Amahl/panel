<?php

namespace App\Filament\App\Pages;

use App\Facades\Activity;
use App\Models\Permission;
use App\Models\Server;
use App\Models\ServerVariable;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Validator;

class Startup extends ServerFormPage
{
    protected static ?string $navigationIcon = 'tabler-player-play';
    protected static ?int $navigationSort = 9;

    public function form(Form $form): Form
    {
        return $form
            ->columns([
                'default' => 1,
                'sm' => 1,
                'md' => 4,
                'lg' => 6,
            ])
            ->schema([
                Textarea::make('startup')
                    ->label('Startup Command')
                    ->columnSpan([
                        'default' => 1,
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ])
                    ->autosize()
                    ->readOnly(),
                Select::make('select_image') //TODO: Show Custom Image if Image !== $egg->docker_images
                    ->label('Docker Image')
                    ->afterStateUpdated(fn (Set $set, $state) => $set('image', $state))
                    ->options(function (Set $set, Server $server) {
                        $images = $server->egg->docker_images ?? [];

                        $currentImage = $server->image;
                        if (!$currentImage && $images) {
                            $defaultImage = collect($images)->first();
                            $set('image', $defaultImage);
                            $set('select_image', $defaultImage);
                        }

                        return array_flip($images);
                    })
                    ->selectablePlaceholder(false)
                    ->columnSpan([
                        'default' => 1,
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 2,
                    ]),
                Section::make('Server Variables')
                    ->schema([
                        Repeater::make('server_variables')
                            ->label('')
                            ->relationship('viewableServerVariables')
                            ->grid()
                            ->reorderable(false)->addable(false)->deletable(false)
                            ->schema(function () {
                                $text = TextInput::make('variable_value')
                                    ->hidden($this->shouldHideComponent(...))
                                    ->disabled(fn (ServerVariable $serverVariable) => !$serverVariable->variable->user_editable)
                                    ->required(fn (ServerVariable $serverVariable) => in_array('required', explode('|', $serverVariable->variable->rules)))
                                    ->rules([
                                        fn (ServerVariable $serverVariable): Closure => function (string $attribute, $value, Closure $fail) use ($serverVariable) {
                                            $validator = Validator::make(['validatorkey' => $value], [
                                                'validatorkey' => $serverVariable->variable->rules,
                                            ]);

                                            if ($validator->fails()) {
                                                $message = str($validator->errors()->first())->replace('validatorkey', $serverVariable->variable->name);

                                                $fail($message);
                                            }
                                        },
                                    ]);

                                $select = Select::make('variable_value')
                                    ->hidden($this->shouldHideComponent(...))
                                    ->disabled(fn (ServerVariable $serverVariable) => !$serverVariable->variable->user_editable)
                                    ->options($this->getSelectOptionsFromRules(...))
                                    ->selectablePlaceholder(false);

                                $components = [$text, $select];

                                foreach ($components as &$component) {
                                    $component = $component
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function ($state, Server $server, ServerVariable $serverVariable) {
                                            $this->update($state, $serverVariable->variable->env_variable, $server);
                                        })
                                        ->hintIcon('tabler-code')
                                        ->label(fn (ServerVariable $serverVariable) => $serverVariable->variable->name)
                                        ->hintIconTooltip(fn (ServerVariable $serverVariable) => $serverVariable->variable->rules)
                                        ->prefix(fn (ServerVariable $serverVariable) => '{{' . $serverVariable->variable->env_variable . '}}')
                                        ->helperText(fn (ServerVariable $serverVariable) => empty($serverVariable->variable->description) ? '—' : $serverVariable->variable->description);
                                }

                                return $components;
                            })
                            ->columnSpan(6),
                    ]),
            ]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(!auth()->user()->can(Permission::ACTION_STARTUP_READ), 403);
    }

    private function shouldHideComponent(ServerVariable $serverVariable, Component $component): bool
    {
        $containsRuleIn = str($serverVariable->variable->rules)->explode('|')->reduce(
            fn ($result, $value) => $result === true && !str($value)->startsWith('in:'), true
        );

        if ($component instanceof Select) {
            return $containsRuleIn;
        }

        if ($component instanceof TextInput) {
            return !$containsRuleIn;
        }

        throw new \Exception('Component type not supported: ' . $component::class);
    }

    private function getSelectOptionsFromRules(ServerVariable $serverVariable): array
    {
        $inRule = str($serverVariable->variable->rules)->explode('|')->reduce(
            fn ($result, $value) => str($value)->startsWith('in:') ? $value : $result, ''
        );

        return str($inRule)
            ->after('in:')
            ->explode(',')
            ->each(fn ($value) => str($value)->trim())
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }

    public function update($state, string $var, Server $server): null
    {
        $variable = $server->variables()->where('env_variable', $var)->first();
        $original = $variable->server_value;

        try {

            $validator = Validator::make(
                ['variable_value' => $state],
                ['variable_value' => $variable->rules]
            );

            if ($validator->fails()) {
                Notification::make()
                    ->danger()
                    ->title('Validation Failed: ' . $variable->name)
                    ->body(implode(', ', $validator->errors()->all()))
                    ->send();

                return null;
            }

            ServerVariable::query()->updateOrCreate([
                'server_id' => $server->id,
                'variable_id' => $variable->id,
            ], [
                'variable_value' => $state ?? '',
            ]);

            if ($variable->env_variable !== $var) {
                Activity::event('server:startup.edit')
                    ->subject($variable)
                    ->property([
                        'variable' => $variable->env_variable,
                        'old' => $original,
                        'new' => $state,
                    ])
                    ->log();
            }
            Notification::make()
                ->success()
                ->title('Updated: ' . $variable->name)
                ->body(fn () => $original . ' -> ' . $state)
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Failed: ' . $variable->name)
                ->body($e->getMessage())
                ->send();
        }

        return null;
    }
}
