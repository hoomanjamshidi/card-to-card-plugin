/** The active theme's colours as swatches (from theme.json or the theme's customizer). */
import { HugeiconsIcon } from '@hugeicons/react';
import { Tick02Icon } from '@hugeicons/core-free-icons';
import { boot, cn, ink } from '../lib/utils';

export function ThemeSwatches( { selected, onPick, empty }: { selected?: string; onPick: ( slug: string, hex: string ) => void; empty?: string } ) {
	const colors = boot().themeColors ?? [];
	if ( ! colors.length ) return empty ? <p className="text-[12.5px] leading-6 text-muted-foreground">{ empty }</p> : null;
	return (
		<div className="flex flex-wrap items-center gap-2">
			{ colors.map( ( c ) => (
				<button key={ c.slug } type="button" title={ `${ c.name } — ${ c.color }` } aria-label={ c.name } aria-pressed={ selected === c.slug } onClick={ () => onPick( c.slug, c.color ) } className={ cn( 'flex size-8 items-center justify-center rounded-full border border-black/10 ring-offset-2 ring-offset-card', selected === c.slug && 'ring-2 ring-foreground' ) } style={ { background: c.color, color: ink( c.color ) } }>
					{ selected === c.slug ? <HugeiconsIcon icon={ Tick02Icon } size={ 14 } /> : null }
				</button>
			) ) }
		</div>
	);
}
