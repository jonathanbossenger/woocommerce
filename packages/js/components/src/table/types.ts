export type QueryProps = {
	orderby?: string;
	order?: string;
	page?: string;
	per_page?: number;
	/**
	 * Allowing string for backward compatibility
	 */
	paged?: number | string;
};

export type TableHeader = {
	/**
	 * Boolean, true if this column is the default for sorting. Only one column should have this set.
	 */
	defaultSort?: boolean;
	/**
	 * String, asc|desc if this column is the default for sorting. Only one column should have this set.
	 */
	defaultOrder?: string;
	/**
	 * Boolean, true if this column should be aligned to the left.
	 */
	isLeftAligned?: boolean;
	/**
	 * Boolean, true if this column is a number value.
	 */
	isNumeric?: boolean;
	/**
	 * Boolean, true if this column is sortable.
	 */
	isSortable?: boolean;
	/**
	 * The API parameter name for this column, passed to `orderby` when sorting via API.
	 */
	key: string;
	/**
	 * The display label for this column.
	 */
	label?: React.ReactNode;
	/**
	 * Boolean, true if this column should always display in the table (not shown in toggle-able list).
	 */
	required?: boolean;
	/**
	 * The label used for screen readers for this column.
	 */
	screenReaderLabel?: string;
	/**
	 * Additional classname for the header cell
	 */
	cellClassName?: string;
	/**
	 * Boolean value to control visibility of a header
	 */
	visible?: boolean;
};

export type TableRow = {
	/**
	 * Display value, used for rendering- strings or elements are best here.
	 */
	display?: React.ReactNode;
	/**
	 * "Real" value used for sorting, and should be a string or number. A column with `false` value will not be sortable.
	 */
	value?: string | number | boolean;
};

/**
 * Props shared between TableProps and TableCardProps.
 */
type CommonTableProps = {
	/**
	 * The rowKey used for the key value on each row, a function that returns the key.
	 * Defaults to index.
	 */
	rowKey?: ( row: TableRow[], index: number ) => number;
	/**
	 * Customize the message to show when there are no rows in the table.
	 */
	emptyMessage?: string;
	/**
	 * The query string represented in object form
	 */
	query?: QueryProps;
	/**
	 * Which column should be the row header, defaults to the first item (`0`) (but could be set to `1`, if the first col
	 * is checkboxes, for example). Set to false to disable row headers.
	 */
	rowHeader?: number | false;
	/**
	 * An array of column headers (see `Table` props).
	 */
	headers?: Array< TableHeader >;
	/**
	 * An array of arrays of display/value object pairs (see `Table` props).
	 */
	rows?: Array< Array< TableRow > >;
	/**
	 * Additional CSS classes.
	 */
	className?: string;
	/**
	 * A function called when sortable table headers are clicked, gets the `header.key` as argument.
	 */
	onSort?: ( key: string, direction: string ) => void;
};

export type TableProps = CommonTableProps & {
	/** A unique ID for this instance of the component. This is automatically generated by withInstanceId. */
	instanceId: number | string;
	/**
	 * Controls whether this component is hidden from screen readers. Used by the loading state, before there is data to read.
	 * Don't use this on real tables unless the table data is loaded elsewhere on the page.
	 */
	ariaHidden?: boolean;
	/**
	 * A label for the content in this table
	 */
	caption?: string;
	/**
	 * Additional classnames
	 */
	classNames?: string | Record< string, string >;
};

export type TableSummaryProps = {
	// An array of objects with `label` & `value` properties, which display on a single line.
	data: Array< {
		label: string;
		value: boolean | number | string | React.ReactNode;
	} >;
};

export type TableCardProps = CommonTableProps & {
	/**
	 * An array of custom React nodes that is placed at the top right corner.
	 */
	actions?: Array< React.ReactNode >;
	/**
	 * If a search is provided in actions and should reorder actions on mobile.
	 */
	hasSearch?: boolean;
	/**
	 * Content to be displayed before the table but after the header.
	 */
	tablePreface?: React.ReactNode;
	/**
	 * A list of IDs, matching to the row list so that ids[ 0 ] contains the object ID for the object displayed in row[ 0 ].
	 */
	ids?: Array< number >;
	/**
	 * Defines if the table contents are loading.
	 * It will display `TablePlaceholder` component instead of `Table` if that's the case.
	 */
	isLoading?: boolean;
	/**
	 * A function which returns a callback function to update the query string for a given `param`.
	 */
	// Allowing any for backward compatibitlity
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	onQueryChange?: ( param: string ) => ( ...props: any ) => void;
	/**
	 * A function which returns a callback function which is called upon the user changing the visibility of columns.
	 */
	onColumnsChange?: ( showCols: Array< string >, key?: string ) => void;
	/**
	 * A callback function that is invoked when the current page is changed.
	 */
	onPageChange?: (
		newPage: number,
		direction?: 'previous' | 'next' | 'goto'
	) => void;
	/**
	 * The total number of rows to display per page.
	 */
	rowsPerPage: number;
	/**
	 * Boolean to determine whether or not ellipsis menu is shown.
	 */
	showMenu?: boolean;
	/**
	 * An array of objects with `label` & `value` properties, which display in a line under the table.
	 * Optional, can be left off to show no summary.
	 */
	summary?: TableSummaryProps[ 'data' ];
	/**
	 * The title used in the card header, also used as the caption for the content in this table.
	 */
	title: string;
	/**
	 * The total number of rows (across all pages).
	 */
	totalRows: number;
};
