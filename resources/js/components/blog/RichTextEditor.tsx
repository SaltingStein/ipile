import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import type { Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import ImageExtension from '@tiptap/extension-image';
import { Markdown } from '@tiptap/markdown';
import { Placeholder } from '@tiptap/extensions';
import { VideoEmbedNode } from './VideoEmbedNode';
import { parseVideoUrl } from '../media/videoEmbed';

/**
 * A word-processor style editor for blog posts (TipTap). It reads and writes Markdown, so posts are stored
 * as Markdown and rendered safely on the server. Images can be added with the toolbar, pasted or dropped in;
 * they are uploaded through `onUploadImage`. YouTube and Vimeo videos are stored as their link on a line of its own.
 */
interface RichTextEditorProps {
    value: string;
    onChange: (markdown: string) => void;
    onUploadImage: (file: File) => Promise<string>;
    labelledBy: string;
    describedBy?: string;
    invalid?: boolean;
    placeholder?: string;
}

export default function RichTextEditor({ value, onChange, onUploadImage, labelledBy, describedBy, invalid = false, placeholder = 'Start writing…' }: RichTextEditorProps) {
    const editorRef = useRef<Editor | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [linkEditing, setLinkEditing] = useState(false);
    const [linkHref, setLinkHref] = useState('');
    const [videoEditing, setVideoEditing] = useState(false);
    const [videoUrl, setVideoUrl] = useState('');
    const [videoError, setVideoError] = useState<string | null>(null);

    /**
     * Upload image files one by one and insert each at the cursor. Returns false when there were no images.
     */
    function insertImages(files: File[]): boolean {
        const images = files.filter((file) => file.type.startsWith('image/'));

        if (images.length === 0) {
            return false;
        }

        setUploading(true);
        setUploadError(null);
        void (async () => {
            try {
                for (const file of images) {
                    const src = await onUploadImage(file);
                    editorRef.current?.chain().focus().setImage({ src, alt: '' }).run();
                }
            } catch (error) {
                setUploadError(error instanceof Error ? error.message : 'The image could not be uploaded.');
            } finally {
                setUploading(false);
            }
        })();

        return true;
    }

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                // Markdown has no underline; leaving it on would lose the formatting when the post is saved.
                underline: false,
                heading: { levels: [1, 2, 3, 4] },
                link: { openOnClick: false, autolink: true, defaultProtocol: 'https' },
            }),
            ImageExtension.configure({ allowBase64: false }),
            VideoEmbedNode,
            Markdown,
            Placeholder.configure({ placeholder }),
        ],
        content: value,
        contentType: 'markdown',
        editorProps: {
            attributes: {
                class: 'help-article blog-article min-h-96 px-4 py-3 focus:outline-none',
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-labelledby': labelledBy,
                ...(describedBy ? { 'aria-describedby': describedBy } : {}),
            },
            handlePaste: (_view, event) => insertImages(Array.from(event.clipboardData?.files ?? [])),
            handleDrop: (_view, event, _slice, moved) => !moved && insertImages(Array.from((event as DragEvent).dataTransfer?.files ?? [])),
        },
        onUpdate: ({ editor: current }) => onChange(current.getMarkdown()),
    });
    editorRef.current = editor;

    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            block: current.isActive('heading', { level: 2 }) ? 'h2' : current.isActive('heading', { level: 3 }) ? 'h3' : 'p',
            bold: current.isActive('bold'),
            italic: current.isActive('italic'),
            strike: current.isActive('strike'),
            bulletList: current.isActive('bulletList'),
            orderedList: current.isActive('orderedList'),
            blockquote: current.isActive('blockquote'),
            codeBlock: current.isActive('codeBlock'),
            link: current.isActive('link'),
            linkHref: (current.getAttributes('link').href as string | undefined) ?? '',
            image: current.isActive('image'),
            imageAlt: (current.getAttributes('image').alt as string | undefined) ?? '',
            canUndo: current.can().undo(),
            canRedo: current.can().redo(),
        }),
    });

    function setBlock(block: string) {
        const chain = editor.chain().focus();

        if (block === 'h2' || block === 'h3') {
            chain.setHeading({ level: block === 'h2' ? 2 : 3 }).run();
        } else {
            chain.setParagraph().run();
        }
    }

    function openLinkEditor() {
        setLinkHref(state.linkHref);
        setLinkEditing(true);
    }

    function applyLink() {
        const href = linkHref.trim();
        const chain = editor.chain().focus().extendMarkRange('link');

        if (href === '') {
            chain.unsetLink().run();
        } else {
            // "example.com" → "https://example.com"; site paths, anchors and mailto: links stay as they are.
            chain.setLink({ href: /^(https?:\/\/|mailto:|\/|#)/i.test(href) ? href : `https://${href}` }).run();
        }

        setLinkEditing(false);
    }

    function insertVideo() {
        if (parseVideoUrl(videoUrl) === null) {
            setVideoError('Paste a YouTube or Vimeo video link, for example https://www.youtube.com/watch?v=…');

            return;
        }

        editor.chain().focus().setVideoEmbed(videoUrl).run();
        setVideoEditing(false);
        setVideoUrl('');
        setVideoError(null);
    }

    return (
        <div className={`flex flex-col rounded-lg border bg-slate-950 ${invalid ? 'border-rose-500' : 'border-slate-700 focus-within:border-emerald-500'}`}>
            <div role="toolbar" aria-label="Formatting" className="sticky top-0 z-10 flex flex-wrap items-center gap-1 rounded-t-lg border-b border-slate-800 bg-slate-900 p-1.5">
                <label className="sr-only" htmlFor="editor-block-type">Text style</label>
                <select id="editor-block-type" value={state.block} onChange={(event) => setBlock(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    <option value="p">Normal text</option>
                    <option value="h2">Heading</option>
                    <option value="h3">Subheading</option>
                </select>
                <Divider />
                <ToolButton label="Bold (Ctrl+B)" active={state.bold} onClick={() => editor.chain().focus().toggleBold().run()}><span className="font-bold">B</span></ToolButton>
                <ToolButton label="Italic (Ctrl+I)" active={state.italic} onClick={() => editor.chain().focus().toggleItalic().run()}><span className="italic">I</span></ToolButton>
                <ToolButton label="Strikethrough" active={state.strike} onClick={() => editor.chain().focus().toggleStrike().run()}><span className="line-through">S</span></ToolButton>
                <ToolButton label="Link" active={state.link} onClick={openLinkEditor}>🔗</ToolButton>
                <Divider />
                <ToolButton label="Bulleted list" active={state.bulletList} onClick={() => editor.chain().focus().toggleBulletList().run()}>• List</ToolButton>
                <ToolButton label="Numbered list" active={state.orderedList} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1. List</ToolButton>
                <ToolButton label="Quote" active={state.blockquote} onClick={() => editor.chain().focus().toggleBlockquote().run()}>❝</ToolButton>
                <ToolButton label="Code block" active={state.codeBlock} onClick={() => editor.chain().focus().toggleCodeBlock().run()}>{'</>'}</ToolButton>
                <ToolButton label="Divider line" onClick={() => editor.chain().focus().setHorizontalRule().run()}>―</ToolButton>
                <Divider />
                <ToolButton label="Insert image" disabled={uploading} onClick={() => fileInput.current?.click()}>{uploading ? 'Uploading…' : '🖼 Image'}</ToolButton>
                <ToolButton label="Insert YouTube or Vimeo video" active={videoEditing} onClick={() => { setVideoEditing(true); setVideoError(null); }}>▶ Video</ToolButton>
                <Divider />
                <ToolButton label="Undo (Ctrl+Z)" disabled={!state.canUndo} onClick={() => editor.chain().focus().undo().run()}>↶</ToolButton>
                <ToolButton label="Redo (Ctrl+Shift+Z)" disabled={!state.canRedo} onClick={() => editor.chain().focus().redo().run()}>↷</ToolButton>
                <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden
                    onChange={(event) => {
                        insertImages(Array.from(event.target.files ?? []));
                        event.target.value = '';
                    }} />
            </div>

            {linkEditing && (
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 bg-slate-900/60 p-2 text-sm">
                    <label htmlFor="editor-link-href" className="text-slate-300">Link to</label>
                    <input id="editor-link-href" autoFocus value={linkHref} onChange={(event) => setLinkHref(event.target.value)}
                        onKeyDown={(event) => {
                            // Enter applies the link instead of submitting the post form.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                applyLink();
                            } else if (event.key === 'Escape') {
                                setLinkEditing(false);
                            }
                        }}
                        placeholder="https://example.com or /f/summit"
                        className="min-w-0 flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100" />
                    <button type="button" onClick={applyLink} className="rounded-md bg-emerald-500 px-3 py-1 font-semibold text-slate-950 hover:bg-emerald-400">Apply</button>
                    {state.link && (
                        <button type="button" onClick={() => { editor.chain().focus().extendMarkRange('link').unsetLink().run(); setLinkEditing(false); }}
                            className="rounded-md border border-slate-700 px-3 py-1 text-slate-200">Remove link</button>
                    )}
                    <button type="button" onClick={() => setLinkEditing(false)} className="px-2 py-1 text-slate-400 hover:text-slate-200">Cancel</button>
                </div>
            )}

            {videoEditing && (
                <div className="flex flex-col gap-1 border-b border-slate-800 bg-slate-900/60 p-2 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <label htmlFor="editor-video-url" className="text-slate-300">Video link</label>
                        <input id="editor-video-url" autoFocus value={videoUrl} onChange={(event) => setVideoUrl(event.target.value)}
                            aria-invalid={videoError ? true : undefined} aria-describedby={videoError ? 'editor-video-error' : undefined}
                            onKeyDown={(event) => {
                                // Enter inserts the video instead of submitting the post form.
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    insertVideo();
                                } else if (event.key === 'Escape') {
                                    setVideoEditing(false);
                                }
                            }}
                            placeholder="https://www.youtube.com/watch?v=… or https://vimeo.com/…"
                            className="min-w-0 flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100" />
                        <button type="button" onClick={insertVideo} className="rounded-md bg-emerald-500 px-3 py-1 font-semibold text-slate-950 hover:bg-emerald-400">Insert</button>
                        <button type="button" onClick={() => setVideoEditing(false)} className="px-2 py-1 text-slate-400 hover:text-slate-200">Cancel</button>
                    </div>
                    {videoError && <p id="editor-video-error" role="alert" className="text-rose-400">{videoError}</p>}
                </div>
            )}

            {state.image && (
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 bg-slate-900/60 p-2 text-sm">
                    <label htmlFor="editor-image-alt" className="text-slate-300">Image description</label>
                    <input id="editor-image-alt" key={state.imageAlt} defaultValue={state.imageAlt}
                        placeholder="What the picture shows, for people who cannot see it"
                        onBlur={(event) => editor.chain().updateAttributes('image', { alt: event.target.value.trim() }).run()}
                        onKeyDown={(event) => {
                            // Enter saves the description instead of submitting the post form.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                        className="min-w-0 flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100" />
                </div>
            )}

            {uploadError && <p role="alert" className="border-b border-slate-800 px-3 py-2 text-sm text-rose-400">{uploadError}</p>}

            <EditorContent editor={editor} />
        </div>
    );
}

interface ToolButtonProps {
    label: string;
    onClick: () => void;
    active?: boolean;
    disabled?: boolean;
    children: ReactNode;
}

function ToolButton({ label, onClick, active, disabled = false, children }: ToolButtonProps) {
    return (
        <button type="button" title={label} aria-label={label} aria-pressed={active} disabled={disabled}
            // Keep the text selection in the editor when a toolbar button is clicked.
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            className={`min-w-8 rounded-md px-2 py-1 text-sm transition focus-visible:outline-2 focus-visible:outline-emerald-400 disabled:opacity-40 ${active ? 'bg-emerald-500/20 text-emerald-200' : 'text-slate-200 hover:bg-slate-800'}`}>
            {children}
        </button>
    );
}

function Divider() {
    return <span aria-hidden="true" className="mx-0.5 h-5 w-px bg-slate-700" />;
}
