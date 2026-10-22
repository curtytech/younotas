@php
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();
    $binding = $applyStateBindingModifiers("\$entangle('{$statePath}')", isOptimisticallyLive: false);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <x-filament::input.wrapper
        :disabled="$isDisabled"
        :valid="! $errors->has($statePath)"
        :prefix="$getPrefixLabel()"
        :suffix="$getSuffixLabel()"
        x-data="{
            state: $wire.{{ $binding }},
            format(value) {
                if (value === null || value === '') return '';
                return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
            input(event) {
                const digits = event.target.value.replace(/\D/g, '');
                this.state = digits === '' ? null : (Number(digits) / 100).toFixed(2);
                event.target.value = this.format(this.state);
            },
        }"
    >
        <x-filament::input
            type="text"
            inputmode="numeric"
            :id="$getId()"
            :disabled="$isDisabled"
            :readonly="$isReadOnly()"
            :required="$isRequired() && ! $isConcealed()"
            :placeholder="$getPlaceholder()"
            :autofocus="$isAutofocused()"
            x-bind:value="format(state)"
            x-on:input="input($event)"
            x-on:blur="{{ $isLiveOnBlur() ? '$wire.$commit()' : '' }}"
            :attributes="\Filament\Support\prepare_inherited_attributes($getExtraInputAttributeBag())"
        />
    </x-filament::input.wrapper>
</x-dynamic-component>
