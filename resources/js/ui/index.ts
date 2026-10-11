/**
 * Biblioteca de componentes do painel.
 *
 * Use nos seus módulos para manter o mesmo visual:
 *
 *   import { Button, Card, DataTable, PageHeader, toast, confirm } from '@/ui';
 *
 * Todos seguem a cor da marca (`brand-*`), funcionam nos temas claro e escuro
 * e respeitam "reduzir movimento" do sistema operacional.
 */
export { default as AnimatedNumber } from './AnimatedNumber.vue';
export { default as Alert } from './Alert.vue';
export { default as Avatar } from './Avatar.vue';
export { default as Badge } from './Badge.vue';
export { default as Button } from './Button.vue';
export { default as Card } from './Card.vue';
export { default as Checkbox } from './Checkbox.vue';
export { default as CommandPalette } from './CommandPalette.vue';
export { default as CopyField } from './CopyField.vue';
export { default as DataTable } from './DataTable.vue';
export { default as DialogHost } from './DialogHost.vue';
export { default as Drawer } from './Drawer.vue';
export { default as Dropdown } from './Dropdown.vue';
export { default as EmptyState } from './EmptyState.vue';
export { default as Field } from './Field.vue';
export { default as Modal } from './Modal.vue';
export { default as PageHeader } from './PageHeader.vue';
export { default as Pagination } from './Pagination.vue';
export { default as Progress } from './Progress.vue';
export { default as Select } from './Select.vue';
export { default as Skeleton } from './Skeleton.vue';
export { default as Spinner } from './Spinner.vue';
export { default as StatCard } from './StatCard.vue';
export { default as Switch } from './Switch.vue';
export { default as Tabs } from './Tabs.vue';
export { default as Textarea } from './Textarea.vue';
export { default as TextInput } from './TextInput.vue';
export { default as ToastHost } from './ToastHost.vue';
export { default as Tooltip } from './Tooltip.vue';

export type { Column } from './DataTable.vue';
export type { TabItem } from './Tabs.vue';
export type { DropdownItem } from './Dropdown.vue';
export type { BadgeTone } from './Badge.vue';

export { toast, errorMessage } from './toast';
export type { ToastOptions } from './toast';
export { confirm, prompt } from './dialog';
export type { DialogOptions, DialogInput } from './dialog';
export { registerCommands, openCommandPalette } from './commands';
export type { Command } from './commands';
export { inputClasses } from './inputClasses';
export * as format from './format';
export { chartOptions, brandColor } from './chart';
