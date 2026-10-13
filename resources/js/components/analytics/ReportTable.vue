<script setup lang="ts">
import type { Row } from '@/types/analytics';
defineProps<{ columns: Record<string, string>; rows: Row[] }>();
</script>
<template>
    <div
        class="overflow-x-auto rounded-lg border"
        tabindex="0"
        aria-label="Scrollable report table"
    >
        <table class="w-full text-left text-sm">
            <thead class="bg-muted">
                <tr>
                    <th
                        v-for="(label, key) in columns"
                        :key="key"
                        scope="col"
                        class="p-3 whitespace-nowrap"
                    >
                        {{ label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index" class="border-t">
                    <td
                        v-for="(_label, key) in columns"
                        :key="key"
                        class="max-w-80 p-3"
                    >
                        {{ row[key] ?? '—' }}
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td
                        :colspan="Object.keys(columns).length"
                        class="p-8 text-center text-muted-foreground"
                    >
                        No records in this reporting period.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
