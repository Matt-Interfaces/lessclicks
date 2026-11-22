<script>
    export let id = '';
    export let type = 'text';
    export let value = '';
    export let placeholder = '';
    export let readonly = false;
    export let disabled = false;
    export let compact = false;
    export let min = undefined;
    export let classes = '';

    $: inputClasses = [
        'lessclicks-input',
        compact && 'lessclicks-input--compact',
        classes
    ].filter(Boolean).join(' ');
</script>

{#if type === 'number'}
    <input
        {id}
        type="number"
        bind:value
        {placeholder}
        {readonly}
        {disabled}
        {min}
        class={inputClasses}
    />
{:else if type === 'url'}
    <input
        {id}
        type="url"
        bind:value
        {placeholder}
        {readonly}
        {disabled}
        {min}
        class={inputClasses}
    />
{:else}
    <input
        {id}
        type="text"
        bind:value
        {placeholder}
        {readonly}
        {disabled}
        {min}
        class={inputClasses}
    />
{/if}
