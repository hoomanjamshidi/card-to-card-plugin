/**
 * Receipt form builder: the built-in fields (file, bank reference, last four digits) with editable
 * labels and visibility, plus custom fields — sortable (Tarhak sortable-list), each with type,
 * label, placeholder, required and options.
 */
import { useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Add01Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import type { ReceiptField } from '../lib/types';
import { Row, SwitchRow, TextArea, TextInput } from '../ui/Field';
import { Segmented } from '../ui/Segmented';
import { SoftSelect } from '../ui/SoftSelect';
import { Sortable } from '../ui/Sortable';
import { DeleteButton } from '../ui/DeleteButton';

const LEVELS = [
	{ value: 'hidden' as const, label: 'نمایش نده' },
	{ value: 'optional' as const, label: 'اختیاری' },
	{ value: 'required' as const, label: 'اجباری' },
];

const TYPES: { value: ReceiptField[ 'type' ]; label: string }[] = [
	{ value: 'text', label: 'متن کوتاه' },
	{ value: 'textarea', label: 'متن چندخطی' },
	{ value: 'number', label: 'عدد' },
	{ value: 'tel', label: 'شماره تلفن' },
	{ value: 'date', label: 'تاریخ' },
	{ value: 'time', label: 'ساعت' },
	{ value: 'select', label: 'انتخاب از فهرست' },
];

export function FieldsBuilder() {
	const { s, set } = useStore();
	const [ open, setOpen ] = useState( '' );
	const field = s.fields.find( ( f ) => f.id === open );
	const update = ( patch: Partial<ReceiptField> ) => set( 'fields', s.fields.map( ( f ) => ( f.id === open ? { ...f, ...patch } : f ) ) );
	const add = () => {
		const f: ReceiptField = { id: `f${ Math.random().toString( 36 ).slice( 2, 8 ) }`, label: 'فیلد جدید', type: 'text', required: false, placeholder: '', options: '', ltr: false };
		set( 'fields', [ ...s.fields, f ] );
		setOpen( f.id );
	};
	return (
		<>
			<Row label="متن انتخاب فایل" stack>
				<TextInput value={ s.file_label } onChange={ ( v ) => set( 'file_label', v ) } aria-label="متن انتخاب فایل" />
			</Row>
			<Row label="شماره پیگیری واریز" stack>
				<div className="flex flex-col gap-2 md:flex-row">
					<TextInput value={ s.ref_label } onChange={ ( v ) => set( 'ref_label', v ) } aria-label="عنوان شماره پیگیری" />
					<Segmented value={ s.ref } onChange={ ( v ) => set( 'ref', v ) } options={ LEVELS } size="sm" />
				</div>
			</Row>
			<Row label="۴ رقم آخر کارت مشتری" help="کمک می‌کند واریز را در صورت‌حساب بانک سریع پیدا کنید." stack>
				<div className="flex flex-col gap-2 md:flex-row">
					<TextInput value={ s.last4_label } onChange={ ( v ) => set( 'last4_label', v ) } aria-label="عنوان ۴ رقم آخر" />
					<Segmented value={ s.last4 } onChange={ ( v ) => set( 'last4', v ) } options={ LEVELS } size="sm" />
				</div>
			</Row>

			<div className="mt-2 flex items-center justify-between px-3">
				<p className="text-[14px] font-semibold">فیلدهای دلخواه</p>
				<button type="button" onClick={ add } className="inline-flex items-center gap-1.5 rounded-full border border-border px-3.5 py-1.5 text-[12.5px] font-bold text-primary hover:bg-primary/5">
					<HugeiconsIcon icon={ Add01Icon } size={ 15 } />
					افزودن فیلد
				</button>
			</div>
			<div className="px-3">
				{ s.fields.length ? (
					<Sortable<ReceiptField>
						items={ s.fields }
						onReorder={ ( next ) => set( 'fields', next ) }
						activeId={ open }
						onSelect={ ( id ) => setOpen( ( o ) => ( o === id ? '' : id ) ) }
						render={ ( f ) => (
							<div className="flex items-center gap-2">
								<span className="min-w-0 flex-1 truncate text-[13.5px] font-bold">{ f.label }</span>
								<span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{ TYPES.find( ( t ) => t.value === f.type )?.label }</span>
								{ f.required ? <span className="rounded-full bg-[#e11d48]/10 px-2 py-0.5 text-[11px] text-[#e11d48]">اجباری</span> : null }
							</div>
						) }
					/>
				) : (
					<p className="rounded-2xl border border-dashed border-border px-4 py-5 text-center text-[12.5px] text-muted-foreground">مثلاً «نام صاحب کارت واریزکننده»، «ساعت واریز» یا «نام بانک مبدأ».</p>
				) }
			</div>
			<AnimatePresence>
				{ field ? (
					<motion.div key={ field.id } initial={ { opacity: 0, height: 0 } } animate={ { opacity: 1, height: 'auto' } } exit={ { opacity: 0, height: 0 } } className="overflow-hidden">
						<div className="mx-3 mt-2 flex flex-col gap-1 rounded-2xl border border-primary/30 bg-primary/[0.03] p-2">
							<Row label="عنوان">
								<TextInput value={ field.label } onChange={ ( v ) => update( { label: v } ) } aria-label="عنوان فیلد" />
							</Row>
							<Row label="نوع">
								<SoftSelect value={ field.type } onValueChange={ ( v ) => update( { type: v as ReceiptField[ 'type' ] } ) } options={ TYPES } />
							</Row>
							<Row label="متن راهنما (placeholder)">
								<TextInput value={ field.placeholder } onChange={ ( v ) => update( { placeholder: v } ) } aria-label="متن راهنما" />
							</Row>
							{ field.type === 'select' ? (
								<Row label="گزینه‌ها" help="هر خط یک گزینه." stack>
									<TextArea value={ field.options } onChange={ ( v ) => update( { options: v } ) } rows={ 3 } />
								</Row>
							) : null }
							<SwitchRow label="اجباری" checked={ field.required } onChange={ ( v ) => update( { required: v } ) } />
							<SwitchRow label="چپ‌به‌راست (اعداد و کدها)" checked={ field.ltr } onChange={ ( v ) => update( { ltr: v } ) } />
							<div className="flex justify-end px-3 pb-2">
								<DeleteButton
									label="حذف فیلد"
									onDelete={ () => {
										set( 'fields', s.fields.filter( ( f ) => f.id !== field.id ) );
										setOpen( '' );
									} }
								/>
							</div>
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</>
	);
}
