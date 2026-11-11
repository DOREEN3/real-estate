function PageHeading({ eyebrow, title, description, action }) {
  return (
    <div className="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
      <div>
        <p className="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-[#B88912]">
          {eyebrow}
        </p>
        <h1 className="text-2xl font-bold tracking-tight text-[#0F2A43] sm:text-3xl">
          {title}
        </h1>
        {description && <p className="mt-2 max-w-2xl text-sm text-slate-500">{description}</p>}
      </div>
      {action}
    </div>
  );
}

export default PageHeading;
