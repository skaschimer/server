<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<search
		class="unified-search-input"
		:class="{ 'unified-search-input--mobile': isSmallMobile }">
		<NcHeaderButton
			v-if="isSmallMobile"
			:aria-label="placeholderText"
			aria-haspopup="dialog"
			:aria-expanded="expanded ? 'true' : 'false'"
			@click="$emit('click', $event)">
			<template #icon>
				<IconMagnify :size="20" />
			</template>
		</NcHeaderButton>
		<div
			v-else
			class="unified-search-input__field"
			:class="{ 'unified-search-input__field--active': isActive }">
			<!-- Resting overlay: centred magnifier + placeholder, purely decorative.
				A full-width input cannot group an icon with its own placeholder, so we
				paint the resting look on top and let clicks fall through to the input. -->
			<div class="unified-search-input__resting" aria-hidden="true">
				<IconMagnify :size="20" />
				<span>{{ placeholderText }}</span>
			</div>
			<IconMagnify class="unified-search-input__icon" :size="20" aria-hidden="true" />
			<input
				ref="inputRef"
				type="text"
				role="combobox"
				class="unified-search-input__input"
				aria-autocomplete="list"
				:aria-expanded="expanded ? 'true' : 'false'"
				:aria-label="placeholderText"
				:placeholder="isActive ? placeholderText : ''"
				:value="query"
				@focus="onFocus"
				@blur="isFocused = false"
				@input="onInput">
			<NcButton
				v-if="query.length > 0"
				variant="tertiary-no-background"
				class="unified-search-input__clear"
				:aria-label="t('core', 'Clear search')"
				@click="clearQuery">
				<template #icon>
					<IconClose :size="20" />
				</template>
			</NcButton>
		</div>
	</search>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { useIsSmallMobile } from '@nextcloud/vue/composables/useIsMobile'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcHeaderButton from '@nextcloud/vue/components/NcHeaderButton'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'

/**
 * The unified-search input that lives in the header.
 *
 * Implemented as a plain <input> rather than NcTextField/NcInputField: those
 * assume a light form background and a floating label, which clashes with the
 * themed header and the resting "button" look and would need heavy overrides of
 * their internals. A custom input also lets us own the combobox semantics the
 * results popover needs. On narrow viewports it collapses to an NcHeaderButton
 * to match the other header items.
 */

const props = defineProps<{
	/** Whether the popover the input controls is open. Bound to aria-expanded. */
	expanded?: boolean
	query: string
}>()

const emit = defineEmits<{
	click: [mouseEvent: MouseEvent]
	focus: [focusEvent: FocusEvent]
	'update:query': [query: string]
}>()

const isSmallMobile = useIsSmallMobile()
const placeholderText = t('core', 'Search apps, files, tags, messages …')

const inputRef = ref<HTMLInputElement>()
const isFocused = ref(false)

/** Active = focused or holding a query; drives the input-vs-button styling. */
const isActive = computed(() => isFocused.value || props.query.length > 0)

/**
 * Track focus for the active styling and bubble the event so the parent can
 * react to it.
 *
 * @param event The focus event
 */
function onFocus(event: FocusEvent) {
	isFocused.value = true
	emit('focus', event)
}

/**
 * Relay the typed value upward.
 *
 * @param event The input event
 */
function onInput(event: Event) {
	emit('update:query', (event.target as HTMLInputElement).value)
}

/** Clear the query and keep focus in the input. */
function clearQuery() {
	emit('update:query', '')
	inputRef.value?.focus()
}

defineExpose({
	getInputElement: (): HTMLInputElement | null => inputRef.value ?? null,
})
</script>

<style lang="scss" scoped>
.unified-search-input {
	// Positioned so z-index applies and the input paints above the search scrim
	position: relative;
	z-index: 51;

	&:not(.unified-search-input--mobile) {
		display: flex;
		align-items: center;
		width: clamp(200px, 35vw, 600px);
		max-width: calc(100% - 32px);
	}

	&--mobile {
		display: contents;
	}

	&__field {
		--resting-background: rgba(0, 0, 0, 0.15);
		--resting-background-hover: rgba(0, 0, 0, 0.22);
		position: relative;
		display: flex;
		align-items: center;
		// Match the default clickable area so the inner <input> (which the global
		// input reset forces to that height) fills the field without an override.
		height: var(--default-clickable-area);
		width: 100%;
		border-radius: var(--border-radius-element, 8px);
		box-shadow: inset 0 2px 0 rgba(0, 0, 0, 0.12);
		// Resting: subdued "button" look that sits on the themed header
		background-color: var(--resting-background);
		-webkit-backdrop-filter: var(--filter-background-blur);
		backdrop-filter: var(--filter-background-blur);
		transition: background-color var(--animation-quick) ease-in-out;

		&:hover:not(.unified-search-input__field--active) {
			background-color: var(--resting-background-hover);
		}

		// Active: real input surface once focused or filled
		&--active {
			background-color: var(--color-main-background);
			box-shadow: none;
		}
	}

	// Resting look: magnifier + placeholder centred as a group, painted over the
	// (empty, pointer-transparent) input. Fades out as the field becomes active.
	&__resting {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		padding-inline: 12px;
		pointer-events: none;
		color: color-mix(in srgb, var(--color-background-plain-text) 70%, var(--color-background-plain));
		transition: opacity var(--animation-quick) ease-in-out;

		span {
			overflow: hidden;
			white-space: nowrap;
			text-overflow: ellipsis;
		}

		.unified-search-input__field--active & {
			opacity: 0;
		}
	}

	// Left magnifier for the active state; hidden while the resting overlay shows
	&__icon {
		position: absolute;
		inset-inline-start: 12px;
		display: flex;
		color: var(--color-main-text);
		opacity: 0;
		pointer-events: none;
		transition: opacity var(--animation-quick) ease-in-out;

		.unified-search-input__field--active & {
			opacity: 1;
		}
	}

	// Only visible once active (at rest it's empty and covered by the overlay),
	// so it's styled for the active/white surface throughout.
	&__input {
		flex: 1;
		min-width: 0;
		height: 100%;
		margin: 0;
		// Leading space for the active magnifier
		padding-inline: calc(var(--default-clickable-area) - var(--default-grid-baseline)) 12px;
		// Opt out of NC's global input chrome (core/css/inputs.scss adds a border,
		// radius and focus box-shadow to any text input not in its exclusion list).
		// !important because that global focus rule outweighs a scoped class.
		border: none !important;
		border-radius: 0 !important;
		box-shadow: none !important;
		background-color: transparent;
		color: var(--color-main-text);
		font-size: var(--default-font-size);

		&::placeholder {
			opacity: 1;
			color: var(--color-text-maxcontrast);
		}

		&:focus-visible {
			outline: none;
		}
	}

	&__clear {
		flex-shrink: 0;
		margin-inline-end: 2px;
	}
}

// On dark themes the plain overlay is nearly invisible on the header, so tint
// the resting background with the primary colour instead.
[data-theme-dark] .unified-search-input__field,
[data-theme-dark-highcontrast] .unified-search-input__field {
	--resting-background: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
	--resting-background-hover: color-mix(in srgb, var(--color-primary-element) 22%, transparent);
}

// Mobile: NcHeaderButton styling to match the other header items
.unified-search-input--mobile :deep(.header-menu) {
	height: var(--default-clickable-area);
}

.unified-search-input--mobile :deep(.header-menu__trigger) {
	--button-size: var(--default-clickable-area) !important;
	height: var(--default-clickable-area) !important;
}

.unified-search-input--mobile :deep(.button-vue) {
	--color-main-text: var(--color-background-plain-text);
	color: var(--color-background-plain-text);
	border-radius: var(--border-radius-element) !important;

	&:hover:not(:disabled) {
		background-color: rgba(0, 0, 0, 0.1) !important;
	}

	&:active:not(:disabled) {
		background-color: rgba(0, 0, 0, 0.15) !important;
	}

	&:focus-visible {
		background-color: rgba(0, 0, 0, 0.1) !important;
		outline: none !important;
		box-shadow: inset 0 0 0 2px var(--color-background-plain-text) !important;
	}
}
</style>
