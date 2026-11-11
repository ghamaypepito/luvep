import { useEffect, useState } from 'react';
import { EditorContent, Node, useEditor, useEditorState } from '@tiptap/react';
import { StarterKit } from '@tiptap/starter-kit';
import { Bold, Heading2, Heading3, ImagePlus, Italic, Link, List, ListOrdered, Quote } from 'lucide-react';

// These nodes keep pasted comparison tables editable without a separate table builder.
const Table = Node.create({
    name: 'articleTable',
    group: 'block',
    content: 'articleTableRow+',
    isolating: true,
    parseHTML: () => [{ tag: 'table' }],
    renderHTML: () => ['table', ['tbody', 0]],
});

const TableRow = Node.create({
    name: 'articleTableRow',
    content: '(articleTableHeader | articleTableCell)+',
    parseHTML: () => [{ tag: 'tr' }],
    renderHTML: () => ['tr', 0],
});

function tableCell(name, tag) {
    return Node.create({
        name,
        content: 'block+',
        isolating: true,
        parseHTML: () => [{ tag }],
        renderHTML: () => [tag, 0],
    });
}

function imageUrl(value) {
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
    } catch {
        return null;
    }
}

const ArticleImage = Node.create({
    name: 'articleImage',
    group: 'block',
    atom: true,
    draggable: true,
    addAttributes: () => ({ src: { default: null }, alt: { default: '' } }),
    parseHTML: () => [{
        tag: 'img[src]',
        getAttrs: (element) => imageUrl(element.getAttribute('src')) ? null : false,
    }],
    renderHTML: ({ HTMLAttributes }) => ['img', { ...HTMLAttributes, referrerpolicy: 'no-referrer' }],
});

const extensions = [
    StarterKit.configure({
        heading: { levels: [2, 3, 4] },
        link: { openOnClick: false },
    }),
    ArticleImage,
    Table,
    TableRow,
    tableCell('articleTableHeader', 'th'),
    tableCell('articleTableCell', 'td'),
];

const tools = [
    { label: 'Bold', icon: Bold, node: 'bold', toggle: (chain) => chain.toggleBold() },
    { label: 'Italic', icon: Italic, node: 'italic', toggle: (chain) => chain.toggleItalic() },
    { label: 'Heading 2', icon: Heading2, node: 'heading', attrs: { level: 2 }, toggle: (chain) => chain.toggleHeading({ level: 2 }) },
    { label: 'Heading 3', icon: Heading3, node: 'heading', attrs: { level: 3 }, toggle: (chain) => chain.toggleHeading({ level: 3 }) },
    { label: 'Bullet list', icon: List, node: 'bulletList', toggle: (chain) => chain.toggleBulletList() },
    { label: 'Numbered list', icon: ListOrdered, node: 'orderedList', toggle: (chain) => chain.toggleOrderedList() },
    { label: 'Block quote', icon: Quote, node: 'blockquote', toggle: (chain) => chain.toggleBlockquote() },
];

export default function ArticleBodyEditor({ value, onChange, disabled, error }) {
    const [link, setLink] = useState(null);
    const [linkError, setLinkError] = useState(false);
    const [image, setImage] = useState(null);
    const [imageError, setImageError] = useState(false);
    const editor = useEditor({
        extensions,
        content: value ?? '',
        immediatelyRender: false,
        editorProps: {
            attributes: {
                class: 'tiptap-body min-h-60 px-4 py-2 text-sm leading-relaxed text-gray-900',
                role: 'textbox',
                'aria-labelledby': 'article-body-label',
                'aria-multiline': 'true',
                id: 'article-body',
            },
        },
        onUpdate: ({ editor }) => onChange(editor.isEmpty ? '' : editor.getHTML()),
    });
    const activeTools = useEditorState({
        editor,
        selector: ({ editor }) => tools.map(({ node, attrs }) => editor?.isActive(node, attrs)),
    });

    useEffect(() => {
        if (!editor) return;
        editor.setEditable(!disabled, false);
        editor.view.dom.setAttribute('aria-invalid', String(Boolean(error)));
        if (error) editor.view.dom.setAttribute('aria-describedby', 'article-body-error');
        else editor.view.dom.removeAttribute('aria-describedby');
    }, [editor, disabled, error]);

    useEffect(() => {
        if (!editor) return;
        const html = editor.isEmpty ? '' : editor.getHTML();
        if ((value ?? '') !== html) editor.commands.setContent(value ?? '', { emitUpdate: false });
    }, [editor, value]);

    function applyLink() {
        const chain = editor.chain().focus().extendMarkRange('link');
        const url = link.trim();
        const applied = url ? chain.setLink({ href: url }).run() : chain.unsetLink().run();
        if (!applied) {
            setLinkError(true);
            return;
        }
        setLink(null);
    }

    function insertImage() {
        const src = imageUrl(image.src.trim());
        if (!src) {
            setImageError(true);
            return;
        }
        editor.chain().focus().insertContent({
            type: 'articleImage',
            attrs: { src, alt: image.alt.trim() },
        }).run();
        setImage(null);
    }

    return (
        <div>
            <p id="article-body-label" className="mb-1.5 text-sm font-medium text-gray-900">Article body</p>
            <div className="rounded-lg border border-gray-300 bg-white focus-within:border-green-700 focus-within:ring-1 focus-within:ring-green-700">
                <div role="group" aria-label="Article formatting" className="flex flex-wrap gap-0.5 rounded-t-lg border-b border-gray-200 bg-gray-50 p-1.5">
                    {tools.map(({ label, icon: Icon, toggle }, index) => (
                        <button key={label} type="button" title={label} aria-label={label} aria-pressed={activeTools?.[index] ?? false} disabled={!editor || disabled}
                            className="grid size-9 place-items-center rounded text-gray-700 hover:bg-gray-200 focus-visible:outline-2 focus-visible:outline-green-700 aria-pressed:bg-green-100 aria-pressed:text-green-900 disabled:opacity-50"
                            onMouseDown={(event) => event.preventDefault()} onClick={() => toggle(editor.chain().focus()).run()}>
                            <Icon size={17} aria-hidden="true" />
                        </button>
                    ))}
                    <button type="button" title="Edit link" aria-label="Edit link" aria-expanded={link !== null} disabled={!editor || disabled}
                        className="grid size-9 place-items-center rounded text-gray-700 hover:bg-gray-200 focus-visible:outline-2 focus-visible:outline-green-700 aria-expanded:bg-green-100 aria-expanded:text-green-900 disabled:opacity-50"
                        onMouseDown={(event) => event.preventDefault()} onClick={() => {
                            setLinkError(false);
                            setImage(null);
                            setLink(link === null ? editor.getAttributes('link').href ?? '' : null);
                        }}>
                        <Link size={17} aria-hidden="true" />
                    </button>
                    <button type="button" title="Add image" aria-label="Add image" aria-expanded={image !== null} disabled={!editor || disabled}
                        className="grid size-9 place-items-center rounded text-gray-700 hover:bg-gray-200 focus-visible:outline-2 focus-visible:outline-green-700 aria-expanded:bg-green-100 aria-expanded:text-green-900 disabled:opacity-50"
                        onMouseDown={(event) => event.preventDefault()} onClick={() => {
                            setLink(null);
                            setImageError(false);
                            setImage(image ? null : { src: '', alt: '' });
                        }}>
                        <ImagePlus size={17} aria-hidden="true" />
                    </button>
                </div>
                {link !== null && (
                    <div className="flex flex-wrap items-end gap-2 border-b border-gray-200 p-2">
                        <label className="min-w-36 flex-1 text-xs font-medium text-gray-800">
                            Link URL
                            <input
                                type="text"
                                inputMode="url"
                                value={link}
                                autoFocus
                                aria-invalid={linkError}
                                aria-describedby={linkError ? 'article-link-error' : undefined}
                                onChange={(event) => { setLink(event.target.value); setLinkError(false); }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') { event.preventDefault(); applyLink(); }
                                }}
                                className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-green-700"
                            />
                        </label>
                        <button type="button" onClick={applyLink} className="min-h-9 rounded-md bg-gray-100 px-2.5 text-sm font-medium text-gray-900 hover:bg-gray-200">Apply link</button>
                        <button type="button" onClick={() => { setLink(null); editor.commands.focus(); }} className="min-h-9 px-2 text-sm text-gray-700 hover:underline">Cancel</button>
                        {linkError && <p id="article-link-error" role="alert" className="basis-full text-xs text-red-800">Enter a valid link URL.</p>}
                    </div>
                )}
                {image && (
                    <div className="flex flex-wrap items-end gap-2 border-b border-gray-200 p-2">
                        <label className="min-w-36 flex-1 text-xs font-medium text-gray-800">
                            Image URL
                            <input type="text" inputMode="url" value={image.src} autoFocus
                                aria-invalid={imageError}
                                aria-describedby={imageError ? 'article-image-url-error' : undefined}
                                onChange={(event) => {
                                    setImage({ ...image, src: event.target.value });
                                    setImageError(false);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') { event.preventDefault(); insertImage(); }
                                }}
                                className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-green-700"
                            />
                        </label>
                        <label className="min-w-36 flex-1 text-xs font-medium text-gray-800">
                            Image description
                            <input type="text" value={image.alt} maxLength={250}
                                onChange={(event) => setImage({ ...image, alt: event.target.value })}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') { event.preventDefault(); insertImage(); }
                                }}
                                className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-green-700"
                            />
                        </label>
                        <button type="button" onClick={insertImage} className="min-h-9 rounded-md bg-gray-100 px-2.5 text-sm font-medium text-gray-900 hover:bg-gray-200">Insert image</button>
                        <button type="button" onClick={() => { setImage(null); editor.commands.focus(); }} className="min-h-9 px-2 text-sm text-gray-700 hover:underline">Cancel</button>
                        {imageError && <p id="article-image-url-error" role="alert" className="basis-full text-xs text-red-800">Enter a public HTTPS image URL.</p>}
                    </div>
                )}
                <EditorContent
                    editor={editor}
                    className="overflow-x-auto [&_img]:my-3 [&_img]:max-w-full [&_img]:rounded-md [&_table]:my-3 [&_table]:w-full [&_table]:border-collapse [&_td]:min-w-24 [&_td]:border [&_td]:border-gray-300 [&_td]:px-2 [&_th]:min-w-24 [&_th]:border [&_th]:border-gray-300 [&_th]:bg-gray-50 [&_th]:px-2 [&_th]:text-left"
                />
            </div>
            {error && <p id="article-body-error" className="mt-1.5 text-sm text-red-800">{error}</p>}
        </div>
    );
}
